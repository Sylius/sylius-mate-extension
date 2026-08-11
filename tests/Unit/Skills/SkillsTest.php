<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;

final class SkillsTest extends TestCase
{
    private const SKILLS_DIR = __DIR__ . '/../../../skills';

    public function testSkillsDirectoryExistsAndIsNotEmpty(): void
    {
        self::assertDirectoryExists(self::SKILLS_DIR);
        self::assertNotSame([], $this->skillDirectories());
    }

    /**
     * The Mate skill installer rejects a skill wholesale if it contains a
     * symlink anywhere inside it — a stray link would silently disable the
     * whole skill for every consumer, so this must never regress.
     */
    public function testSkillsContainNoSymlinks(): void
    {
        foreach ($this->allFiles(self::SKILLS_DIR) as $path) {
            self::assertFalse(is_link($path), \sprintf('"%s" must not be a symlink.', $path));
        }
    }

    public function testEachSkillHasAValidSkillMdWithFrontmatter(): void
    {
        foreach ($this->skillDirectories() as $dir) {
            $skillMd = $dir . '/SKILL.md';
            self::assertFileExists($skillMd, \sprintf('Skill "%s" is missing SKILL.md.', basename($dir)));

            $frontmatter = $this->parseFrontmatter(file_get_contents($skillMd));

            self::assertArrayHasKey('name', $frontmatter, \sprintf('"%s" frontmatter is missing "name".', $skillMd));
            self::assertNotSame('', trim($frontmatter['name']), \sprintf('"%s" has an empty "name".', $skillMd));

            self::assertArrayHasKey('description', $frontmatter, \sprintf('"%s" frontmatter is missing "description".', $skillMd));
            self::assertNotSame('', trim($frontmatter['description']), \sprintf('"%s" has an empty "description".', $skillMd));
        }
    }

    /**
     * @return list<string>
     */
    private function skillDirectories(): array
    {
        $dirs = glob(self::SKILLS_DIR . '/*', GLOB_ONLYDIR);

        return $dirs === false ? [] : $dirs;
    }

    /**
     * @return list<string>
     */
    private function allFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                $files[] = $path;
                continue;
            }

            if (is_dir($path)) {
                $files = [...$files, ...$this->allFiles($path)];
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * Minimal frontmatter reader: only pulls flat "key: value" lines from the
     * leading "---" block, which is all this guard needs to check.
     *
     * @return array<string, string>
     */
    private function parseFrontmatter(string $content): array
    {
        if (!preg_match('/\A---\n(.*?)\n---\n/s', $content, $matches)) {
            return [];
        }

        $frontmatter = [];
        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^([a-zA-Z0-9_-]+):\s*(.*)$/', $line, $pair)) {
                $frontmatter[$pair[1]] = $pair[2];
            }
        }

        return $frontmatter;
    }
}
