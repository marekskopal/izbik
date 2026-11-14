<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * The pattern `Trowelled` (a trowelled concrete-look plaster or microcement without joints, the darker patches in
 * `accent`, drawn as one patch `tile_width` × `tile_height`) and concrete-look plasters in grey, light grey and
 * anthracite.
 */
final class SeedConcretePlasterMigration extends Migration
{
	private const array Patterns = [
		'Plain',
		'Wood',
		'Planks',
		'Tiles',
		'Glass',
		'Grass',
		'Gravel',
		'Terrazzo',
		'Brick',
		'Herringbone',
		'BeaverTail',
		'Stone',
		'Formwork',
		'Marble',
		'Chevron',
		'Zellige',
		'Encaustic',
		'Fluted',
	];

	private const array Codes = [
		'plaster-concrete-grey',
		'plaster-concrete-light',
		'plaster-concrete-anthracite',
	];

	public function up(): void
	{
		$this->table('materials')
			->alterColumn('pattern', Type::Enum, enum: [...self::Patterns, 'Trowelled'], default: 'Plain')
			->alter();

		$now = date('Y-m-d H:i:s');
		$material = static fn (string $code, string $name, string $color, string $accent): array => [
			'code' => $code,
			'name' => $name,
			'pattern' => 'Trowelled',
			'color' => $color,
			'accent' => $accent,
			'roughness' => 0.85,
			'metalness' => 0.0,
			'tile_width' => 2400,
			'tile_height' => 2400,
			'created_at' => $now,
			'updated_at' => $now,
		];

		$this->table('materials')->insert([
			$material('plaster-concrete-grey', 'Concrete-look trowelled plaster, grey', '#9a958b', '#807869'),
			$material('plaster-concrete-light', 'Concrete-look trowelled plaster, light grey', '#c4c0b8', '#a8a399'),
			$material('plaster-concrete-anthracite', 'Concrete-look trowelled plaster, anthracite', '#5f5c57', '#46433f'),
		]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$pdo->exec("UPDATE materials SET pattern = 'Plain' WHERE pattern = 'Trowelled'");
		$this->table('materials')->alterColumn('pattern', Type::Enum, enum: self::Patterns, default: 'Plain')->alter();
	}
}
