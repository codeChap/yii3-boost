<?php

declare(strict_types=1);

namespace codechap\yii3boost\tests\Mcp\Search;

use codechap\yii3boost\Mcp\Search\SearchIndexManager;
use PHPUnit\Framework\TestCase;

class SearchIndexManagerTest extends TestCase
{
    private SearchIndexManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SearchIndexManager(':memory:');
        $this->manager->createSchema();
    }

    public function testCreateSchema(): void
    {
        // Call twice to verify idempotency
        $this->manager->createSchema();
        $this->manager->createSchema();

        $stats = $this->manager->getStats();
        $this->assertSame(0, $stats['total_sections']);
        $this->assertArrayHasKey('sources', $stats);
        $this->assertArrayHasKey('categories', $stats);
    }

    public function testIndexAndSearch(): void
    {
        $this->indexTestContent();

        $results = $this->manager->search('migration');

        $this->assertNotEmpty($results);

        // Results should include the migration section
        $titles = array_column($results, 'section_title');
        $this->assertTrue(
            in_array('Usage Example', $titles) || in_array('Best Practices', $titles),
            'Expected migration-related sections in results',
        );

        // Verify result structure
        $first = $results[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('source', $first);
        $this->assertArrayHasKey('category', $first);
        $this->assertArrayHasKey('file_path', $first);
        $this->assertArrayHasKey('file_title', $first);
        $this->assertArrayHasKey('section_title', $first);
        $this->assertArrayHasKey('body', $first);
        $this->assertArrayHasKey('rank', $first);
    }

    public function testEmptyQueryReturnsEmpty(): void
    {
        $this->indexTestContent();

        $results = $this->manager->search('');
        $this->assertEmpty($results);

        $results = $this->manager->search('   ');
        $this->assertEmpty($results);
    }

    public function testGetStats(): void
    {
        $this->indexTestContent();

        $stats = $this->manager->getStats();

        $this->assertSame(5, $stats['total_sections']);
        $this->assertArrayHasKey('total_sections', $stats);
        $this->assertArrayHasKey('sources', $stats);
        $this->assertArrayHasKey('categories', $stats);
        $this->assertArrayHasKey('last_rebuild', $stats);
        $this->assertArrayHasKey('db_path', $stats);
        $this->assertSame(['bundled' => 5], $stats['sources']);
        $this->assertSame(['cache' => 1, 'database' => 4], $stats['categories']);
        $this->assertSame(':memory:', $stats['db_path']);
    }

    public function testClearIndex(): void
    {
        $this->indexTestContent();

        $stats = $this->manager->getStats();
        $this->assertGreaterThan(0, $stats['total_sections']);

        $this->manager->clearIndex();

        $stats = $this->manager->getStats();
        $this->assertSame(0, $stats['total_sections']);
    }

    public function testMetadata(): void
    {
        $this->manager->setMeta('test_key', 'test_value');
        $this->assertSame('test_value', $this->manager->getMeta('test_key'));

        // Update existing key
        $this->manager->setMeta('test_key', 'updated_value');
        $this->assertSame('updated_value', $this->manager->getMeta('test_key'));

        // Non-existent key returns null
        $this->assertNull($this->manager->getMeta('nonexistent_key'));
    }

    /**
     * Index test content for search tests.
     */
    private function indexTestContent(): void
    {
        $this->manager->indexSections(
            'bundled',
            'database',
            'database/yii-migration.md',
            'Yii3 Database Migration',
            [
                [
                    'section_title' => 'Usage Example',
                    'body' => 'Use migrations to manage database schema changes. '
                        . 'The migration tool creates versioned migration files.',
                ],
                [
                    'section_title' => 'Best Practices',
                    'body' => 'Always use safe migration methods. '
                        . 'Never modify an already-applied migration.',
                ],
            ],
        );

        $this->manager->indexSections(
            'bundled',
            'database',
            'database/yii-active-record.md',
            'Yii3 Active Record',
            [
                [
                    'section_title' => 'Introduction',
                    'body' => 'Active Record provides an object-oriented interface '
                        . 'for accessing and manipulating data stored in databases.',
                ],
                [
                    'section_title' => 'Relations',
                    'body' => 'Use hasMany and hasOne to define relations between models.',
                ],
            ],
        );

        $this->manager->indexSections(
            'bundled',
            'cache',
            'cache/yii-cache.md',
            'Yii3 Caching',
            [
                [
                    'section_title' => 'Cache Components',
                    'body' => 'Yii3 supports various cache backends: FileCache, '
                        . 'ArrayCache, and other PSR-16 compatible implementations.',
                ],
            ],
        );
    }
}
