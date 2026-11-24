<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use Izbik\Model\Entity\Project;
use MarekSkopal\ORM\Query\Expression\RawExpression;
use function is_numeric;

/** @extends ARepository<Project> */
final class ProjectRepository extends ARepository
{
	public function findById(int $projectId): ?Project
	{
		return $this->findOne(['id' => $projectId]);
	}

	/** @return list<Project> */
	public function findAllOrdered(): array
	{
		return $this->select()->orderBy('updated_at', 'DESC')->fetchAll();
	}

	/** @return list<int> */
	public function findAllIds(): array
	{
		$rows = $this->select()->columns(['id'])->fetchAssocAll();

		return array_map(static fn (array $row): int => is_numeric($row['id']) ? (int) $row['id'] : 0, $rows);
	}

	/** @return list<Project> */
	public function findByOwner(int $ownerId): array
	{
		return $this->select()->where(['owner_id' => $ownerId])->orderBy('updated_at', 'DESC')->fetchAll();
	}

	/**
	 * How many projects each user owns; users without any are missing.
	 *
	 * @return array<int, int> by owner id
	 */
	public function countPerOwner(): array
	{
		$rows = $this->select()
			->columns(['owner_id', new RawExpression('COUNT(*) AS projects')])
			->where(['owner_id', '!=', null])
			->groupBy(['owner_id'])
			->fetchAssocAll();
		$counts = [];
		foreach ($rows as $row) {
			$ownerId = $row['owner_id'];
			$projects = $row['projects'];
			if (is_numeric($ownerId) && is_numeric($projects)) {
				$counts[(int) $ownerId] = (int) $projects;
			}
		}

		return $counts;
	}

	/** @return list<Project> */
	public function findWithoutOwner(): array
	{
		return $this->select()->where(['owner_id' => null])->fetchAll();
	}

	public function countByOwner(int $ownerId): int
	{
		return $this->select()->where(['owner_id' => $ownerId])->count();
	}
}
