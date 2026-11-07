<?php

declare(strict_types=1);

namespace Izbik;

require_once __DIR__ . '/../vendor/autoload.php';

use Izbik\App\ApplicationFactory;
use Izbik\Mcp\McpUserContext;
use Izbik\Response\ErrorResponse;
use Izbik\Service\Actor\ActorContext;
use Izbik\Service\Queue\JobQueue;
use Izbik\Service\Realtime\ProjectUpdatePublisher;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Log\LoggerInterface;

$application = ApplicationFactory::create();

$logger = $application->container->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);

$actorContext = $application->container->get(ActorContext::class);
assert($actorContext instanceof ActorContext);
$mcpUserContext = $application->container->get(McpUserContext::class);
assert($mcpUserContext instanceof McpUserContext);
$updatePublisher = $application->container->get(ProjectUpdatePublisher::class);
assert($updatePublisher instanceof ProjectUpdatePublisher);
$jobQueue = $application->container->get(JobQueue::class);
assert($jobQueue instanceof JobQueue);

$emitter = new SapiEmitter();

$handler = static function () use ($application, $logger, $emitter, $actorContext, $mcpUserContext, $updatePublisher, $jobQueue): void {
	// Per-request contexts in this long-lived worker.
	$actorContext->setHuman();
	$mcpUserContext->clear();
	$updatePublisher->reset();
	$jobQueue->reset();

	try {
		$request = ServerRequestFactory::fromGlobals();
		$response = $application->handler->handle($request);
		$emitter->emit($response);
	} catch (\Throwable $e) {
		$logger->error($e->getMessage(), ['exception' => $e]);
		$response = ErrorResponse::fromException($e);
		$emitter->emit($response);
	}

	// Live updates (of committed changes only) and background jobs (of a successful request only) go out after the client
	// has its response.
	frankenphp_finish_request();
	$updatePublisher->flush();
	$jobQueue->flushAfter($response);
};

while (frankenphp_handle_request($handler)) {
	$application->dbContext->resetOrmState();
	gc_collect_cycles();
}
