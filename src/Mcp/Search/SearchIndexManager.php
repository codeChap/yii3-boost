<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Search;

/**
 * Manages the FTS5 search index stored in a SQLite database.
 *
 * Handles schema creation, section indexing, BM25-ranked querying,
 * and index statistics. Uses raw PDO (not the Yii3 DB component)
 * since the search index is a separate SQLite file.
 */
final class SearchIndexManager
{
    private readonly \PDO $pdo;

    public function __construct(
        private readonly string $dbPath,
    ) {
        if ($dbPath !== ':memory:') {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->pdo = new \PDO('sqlite:' . $dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        if ($dbPath !== ':memory:') {
            $this->pdo->exec('PRAGMA journal_mode=WAL');
        }
    }

    public function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS search_sections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL,
                category TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_title TEXT NOT NULL,
                section_title TEXT NOT NULL,
                body TEXT NOT NULL,
                indexed_at TEXT NOT NULL
            )
        ');

        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='search_fts'",
        );
        if ($stmt->fetch() === false) {
            $this->pdo->exec("
                CREATE VIRTUAL TABLE search_fts USING fts5(
                    file_title, section_title, body,
                    content='search_sections', content_rowid='id',
                    tokenize='porter unicode61'
                )
            ");
        }

        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS search_sections_ai AFTER INSERT ON search_sections BEGIN
                INSERT INTO search_fts(rowid, file_title, section_title, body)
                VALUES (new.id, new.file_title, new.section_title, new.body);
            END
        ");

        $this->pdo->exec("
            CREATE TRIGGER IF NOT EXISTS search_sections_ad AFTER DELETE ON search_sections BEGIN
                INSERT INTO search_fts(search_fts, rowid, file_title, section_title, body)
                VALUES ('delete', old.id, old.file_title, old.section_title, old.body);
            END
        ");

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS search_index_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');
    }

    public function clearIndex(): void
    {
        $this->pdo->exec('DELETE FROM search_sections');
        $this->setMeta('last_rebuild', '');
        $this->setMeta('section_count', '0');
    }

    public function indexSections(
        string $source,
        string $category,
        string $filePath,
        string $fileTitle,
        array $sections,
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO search_sections (source, category, file_path, file_title, section_title, body, indexed_at)
            VALUES (:source, :category, :file_path, :file_title, :section_title, :body, :indexed_at)
        ');

        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($sections as $section) {
            if (empty($section['body'])) {
                continue;
            }

            $stmt->execute([
                ':source' => $source,
                ':category' => $category,
                ':file_path' => $filePath,
                ':file_title' => $fileTitle,
                ':section_title' => $section['section_title'],
                ':body' => $section['body'],
                ':indexed_at' => $now,
            ]);
            $count++;
        }

        return $count;
    }

    public function search(string $query, string $category = 'all', int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $ftsQuery = $this->buildFtsQuery($query);

        $sql = '
            SELECT
                s.id, s.source, s.category, s.file_path,
                s.file_title, s.section_title, s.body, rank
            FROM search_fts f
            JOIN search_sections s ON f.rowid = s.id
            WHERE search_fts MATCH :query
        ';

        $params = [':query' => $ftsQuery];

        if ($category !== 'all') {
            $sql .= ' AND s.category = :category';
            $params[':category'] = $category;
        }

        $sql .= ' ORDER BY rank LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getStats(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM search_sections')->fetchColumn();

        $sources = [];
        $stmt = $this->pdo->query('SELECT source, COUNT(*) AS cnt FROM search_sections GROUP BY source');
        while ($row = $stmt->fetch()) {
            $sources[$row['source']] = (int) $row['cnt'];
        }

        $categories = [];
        $stmt = $this->pdo->query('SELECT category, COUNT(*) AS cnt FROM search_sections GROUP BY category');
        while ($row = $stmt->fetch()) {
            $categories[$row['category']] = (int) $row['cnt'];
        }

        return [
            'total_sections' => $total,
            'sources' => $sources,
            'categories' => $categories,
            'last_rebuild' => $this->getMeta('last_rebuild') ?: 'never',
            'db_path' => $this->dbPath,
        ];
    }

    public function getCategories(): array
    {
        return $this->pdo->query('SELECT DISTINCT category FROM search_sections ORDER BY category')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO search_index_meta (key, value) VALUES (:key, :value)
            ON CONFLICT(key) DO UPDATE SET value = :value
        ');
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    public function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM search_index_meta WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (string) $result : null;
    }

    public static function isFts5Available(): bool
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec("CREATE VIRTUAL TABLE _fts5_test USING fts5(content)");
            $pdo->exec("DROP TABLE _fts5_test");
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    private function buildFtsQuery(string $query): string
    {
        if (preg_match('/["\*]|(?:^|\s)(?:AND|OR|NOT)(?:\s|$)/', $query)) {
            return $query;
        }

        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words) || count($words) === 1) {
            return $query;
        }

        $escaped = str_replace('"', '', $query);
        return '"' . $escaped . '" OR ' . implode(' OR ', $words);
    }
}
