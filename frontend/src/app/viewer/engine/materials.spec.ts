import {Material} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {herringboneLength, herringboneTiles, MaterialLibrary, trowelledAccent, trowelledShades} from './materials';

describe('trowelled plaster', () => {
    const random = (seed: number): (() => number) => {
        let state = seed;
        return (): number => {
            state = (state * 1103515245 + 12345) % 2147483648;
            return state / 2147483648;
        };
    };
    const [width, height] = [300, 200];
    const shades = trowelledShades(width, height, 4, random(7));
    const at = (x: number, y: number): number => shades[y * width + x];

    it('varies round its base colour, mostly within half the way to its accent or the lighter shade', () => {
        const values = Array.from(shades);
        const mean = values.reduce((sum, value) => sum + value, 0) / values.length;
        expect(Math.abs(mean)).toBeLessThan(0.2);
        expect(values.filter((value) => Math.abs(value) < 0.5).length / values.length).toBeGreaterThan(0.9);
        expect(Math.max(...values) - Math.min(...values)).toBeGreaterThan(0.4);
    });

    it('wraps round its edges, so the patch tiles without a seam', () => {
        // The last column and the first (the last row and the first) differ about as much as neighbouring ones inside
        // do; two unrelated columns differ 1.6–2.7 times as much, so a seam would show here.
        const step = (pairs: [number, number][]): number => pairs.reduce((sum, [a, b]) => sum + Math.abs(a - b), 0) / pairs.length;
        const median = (values: number[]): number => [...values].sort((a, b) => a - b)[values.length >> 1];
        const rows = Array.from({length: height}, (_, y) => y);
        const columns = Array.from({length: width}, (_, x) => x);
        const columnStep = median(columns.slice(1).map((x) => step(rows.map((y) => [at(x - 1, y), at(x, y)]))));
        const rowStep = median(rows.slice(1).map((y) => step(columns.map((x) => [at(x, y - 1), at(x, y)]))));
        expect(step(rows.map((y) => [at(width / 3, y), at(0, y)]))).toBeGreaterThan(1.4 * columnStep);
        expect(step(rows.map((y) => [at(width - 1, y), at(0, y)]))).toBeLessThan(1.4 * columnStep);
        expect(step(columns.map((x) => [at(x, height - 1), at(x, 0)]))).toBeLessThan(1.4 * rowStep);
    });

    it('is the same on every load', () => {
        expect(trowelledShades(width, height, 4, random(7))).toEqual(shades);
    });

    it('keeps a coarse patch of a large plaster from filling with pores, and a small one from wrapping its strokes', () => {
        const mean = (values: Float32Array): number => values.reduce((sum, value) => sum + value, 0) / values.length;
        expect(Math.abs(mean(trowelledShades(200, 200, 25, random(3))))).toBeLessThan(0.2);
        expect(Math.abs(mean(trowelledShades(40, 40, 5, random(3))))).toBeLessThan(0.35);
    });

    it('darkens its patches towards the accent, else a slightly darker base, also on a dark base', () => {
        const lightness = (color: string): number => new THREE.Color(color).getHSL({h: 0, s: 0, l: 0}, THREE.SRGBColorSpace).l;
        const plaster = (color: string, accent: string | null): Material => ({
            id: 1,
            code: 'p',
            name: 'P',
            pattern: 'Trowelled',
            color,
            accent,
            roughness: 0.85,
            metalness: 0,
            tileWidth: 2400,
            tileHeight: 2400,
            texture: null,
        });
        expect(trowelledAccent(plaster('#9a958b', '#807869'))).toBe('#807869');
        for (const color of ['#4a4744', '#9a958b', '#e4e0d8']) {
            const accent = trowelledAccent(plaster(color, null));
            expect(lightness(accent)).toBeLessThan(lightness(color));
            expect(lightness(color) - lightness(accent)).toBeLessThan(0.15);
        }
    });
});

describe('herringbone', () => {
    it('rounds the tile length to a whole multiple of the width', () => {
        expect(herringboneLength(240, 60)).toBe(240);
        expect(herringboneLength(250, 60)).toBe(240);
        expect(herringboneLength(100, 60)).toBe(120);
    });

    it('covers its patch exactly once and repeats seamlessly across the edges', () => {
        const length = 240;
        const width = 60;
        const size = 2 * length;
        const tiles = herringboneTiles(length, width);
        // Count the tiles over sample points, the patch's own and those one patch further (which must match).
        for (let x = 2.5; x < size; x += 5) {
            for (let y = 2.5; y < size; y += 5) {
                const at = (px: number, py: number): number =>
                    tiles.filter(([tx, ty, tw, th]) => px > tx && px < tx + tw && py > ty && py < ty + th).length;
                expect(at(x, y)).toBe(1);
            }
        }
        // A tile cut by the left or bottom edge reappears, shifted by the patch, cut by the right or top edge.
        const keys = new Set(tiles.map(([tx, ty, tw, th]) => `${tx},${ty},${tw},${th}`));
        const leftCut = tiles.filter(([tx]) => tx < 0);
        const bottomCut = tiles.filter(([, ty]) => ty < 0);
        expect(leftCut.length).toBeGreaterThan(0);
        expect(bottomCut.length).toBeGreaterThan(0);
        for (const [tx, ty, tw, th] of leftCut) {
            expect(keys.has(`${tx + size},${ty},${tw},${th}`)).toBe(true);
        }
        for (const [tx, ty, tw, th] of bottomCut) {
            expect(keys.has(`${tx},${ty + size},${tw},${th}`)).toBe(true);
        }
    });
});

describe('MaterialLibrary', () => {
    const dto = (id: number, pattern: Material['pattern'], tileWidth: number | null, tileHeight: number | null): Material => ({
        id,
        code: `m${id}`,
        name: `Material ${id}`,
        pattern,
        color: '#a0a0a0',
        roughness: 0.8,
        metalness: 0,
        tileWidth,
        tileHeight,
        texture: null,
    });
    const library = new MaterialLibrary([dto(1, 'Planks', 120, 4000), dto(2, 'Stone', 400, 130), dto(3, 'Tiles', 600, 300)], 1);
    const map = (material: THREE.Material): THREE.Texture => (material as THREE.MeshStandardMaterial).map!;

    it('draws planks of a size at that size, along v on floors and along a wall on upright faces', () => {
        const floor = map(library.get(1));
        // A patch of 20 boards 120 wide (2.4 m) by one 4 m length.
        expect(floor.repeat.x).toBeCloseTo(1000 / 2400, 9);
        expect(floor.repeat.y).toBeCloseTo(1000 / 4000, 9);
        expect(floor.rotation).toBe(0);
        expect(map(library.upright(1)).rotation).toBeCloseTo(Math.PI / 2, 9);
        // A grain asked for elsewhere (a stair tread, a cabinet) leaves planks along v, as before.
        expect(map(library.get(1, 'horizontal')).rotation).toBe(0);
        // Planks without a size are drawn along the canvas width: turned onto v, and left along a wall.
        const unsized = new MaterialLibrary([dto(4, 'Planks', null, null)], 1);
        expect(map(unsized.get(4)).rotation).toBeCloseTo(Math.PI / 2, 9);
        expect(map(unsized.get(4, 'horizontal')).rotation).toBeCloseTo(Math.PI / 2, 9);
        expect(map(unsized.upright(4)).rotation).toBe(0);
    });

    it('lays stone walling in a patch of 6 stones by 8 courses, and other patterns alike on upright faces', () => {
        const stone = map(library.get(2));
        expect(stone.repeat.x).toBeCloseTo(1000 / 2400, 9);
        expect(stone.repeat.y).toBeCloseTo(1000 / 1040, 9);
        expect(library.upright(2)).toBe(library.get(2));
        expect(library.upright(3)).toBe(library.get(3));
    });

    it('draws a trowelled plaster as one patch of its size, or 2.4 m square without one, on the surface UVs', () => {
        const plaster = new MaterialLibrary([{...dto(4, 'Trowelled', 1500, 1200), accent: '#807869'}, dto(5, 'Trowelled', null, null)], 1);
        expect(map(plaster.get(4)).repeat.x).toBeCloseTo(1000 / 1500, 9);
        expect(map(plaster.get(4)).repeat.y).toBeCloseTo(1000 / 1200, 9);
        expect(map(plaster.get(5)).repeat.x).toBeCloseTo(1000 / 2400, 9);
        expect(plaster.upright(4)).toBe(plaster.get(4));
    });
});
