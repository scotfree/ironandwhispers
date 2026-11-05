<?php
/**
 * What BGA's Studio build checker refuses, caught before a deploy.
 *
 * Each of these was found by "Dry run build" on the Studio first. They are
 * source lints rather than behaviour tests, because the checker is too.
 */
declare(strict_types=1);

function test_nothing_the_game_ships_reads_a_file_at_runtime(): void
{
    // "Forbidden PHP function detected". Static data comes from Material.php.
    $forbidden = ['file_get_contents', 'fopen', 'file_put_contents', 'readfile', 'scandir', 'glob('];
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/modules/php')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $number => $line) {
            foreach ($forbidden as $name) {
                if (str_contains($line, $name)) {
                    $found[] = basename($file->getPathname()) . ':' . ($number + 1) . " {$name}";
                }
            }
        }
    }
    assertSame([], $found);
}

function test_no_stylesheet_imports_anything(): void
{
    // "@import should not be used in CSS files". Fonts ship in fonts/ and are
    // loaded with @font-face instead. Checks the built CSS, which is what the
    // Studio scans, and the manual, which ships beside it.
    $root = dirname(__DIR__);
    $found = [];
    foreach (['ironandwhispers.css', 'rules.html'] as $name) {
        foreach (file("{$root}/{$name}") as $number => $line) {
            if (str_contains($line, '@import')) {
                $found[] = "{$name}:" . ($number + 1);
            }
        }
    }
    assertSame([], $found);
}

function test_every_name_for_the_project_agrees(): void
{
    // BGA wants the project named exactly after the game, finds Game.php by a
    // namespace built from the project name, and loads <project>.css. A mismatch
    // fails only on the Studio, or at the alpha request.
    $root = dirname(__DIR__);
    $project = json_decode(file_get_contents("{$root}/tools/deploy.json"), true)['remote_dir'];

    preg_match('/"game_name":\s*"([^"]+)"/', file_get_contents("{$root}/gameinfos.jsonc"), $name);
    assertSame($project, strtolower(str_replace(' ', '', $name[1])));

    preg_match('/^namespace Bga\\\\Games\\\\(\w+);/m', file_get_contents("{$root}/modules/php/Game.php"), $namespace);
    assertSame($project, strtolower($namespace[1]));

    assertTrue(file_exists("{$root}/{$project}.css"), "no {$project}.css");
    assertSame($project, json_decode(file_get_contents("{$root}/package.json"), true)['name']);
}

function test_every_bundled_font_carries_its_licence(): void
{
    // "If you use extra fonts, they should be freeware (please include a .txt
    // with the license information in addition to the font files)".
    $fonts = dirname(__DIR__) . '/fonts';
    assertTrue(glob("{$fonts}/*.ttf") !== [], 'no fonts bundled');
    assertTrue(glob("{$fonts}/*.txt") !== [], 'fonts/ has no licence .txt');
}
