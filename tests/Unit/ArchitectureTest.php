<?php

declare(strict_types=1);

namespace Tgi\LaravelPhpRedisSentinel\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class ArchitectureTest extends TestCase
{
    private const SOURCE = __DIR__.'/../../src/';

    private const NAMESPACE = 'Tgi\\LaravelPhpRedisSentinel\\';

    public function test_source_files_declare_strict_types(): void
    {
        foreach ($this->files() as $file) {
            $this->assertStringContainsString(
                'declare(strict_types=1);',
                (string) file_get_contents($file->getPathname()),
                "[{$file->getPathname()}] does not declare strict types.",
            );
        }
    }

    public function test_classes_are_final(): void
    {
        foreach ($this->classes() as $class) {
            if ($class->isInterface() || $class->isTrait() || $class->isEnum()) {
                continue;
            }

            $this->assertTrue($class->isFinal(), "[{$class->getName()}] is not final.");
        }
    }

    public function test_classes_are_marked_as_api_or_internal(): void
    {
        foreach ($this->classes() as $class) {
            $docComment = (string) $class->getDocComment();

            $this->assertMatchesRegularExpression(
                '/@(api|internal)\b/',
                $docComment,
                "[{$class->getName()}] must be marked @api (documented public surface) or @internal.",
            );
        }
    }

    public function test_source_does_not_reference_predis_or_application_code(): void
    {
        foreach ($this->files() as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(Predis|App)\\\\/',
                (string) file_get_contents($file->getPathname()),
                "[{$file->getPathname()}] references Predis or application code.",
            );
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function files(): iterable
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SOURCE, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    /**
     * @return iterable<ReflectionClass<object>>
     */
    private function classes(): iterable
    {
        $source = (string) realpath(self::SOURCE);

        foreach ($this->files() as $file) {
            $relative = substr((string) $file->getRealPath(), strlen($source) + 1, -4);

            /** @var class-string $class */
            $class = self::NAMESPACE.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            yield new ReflectionClass($class);
        }
    }
}
