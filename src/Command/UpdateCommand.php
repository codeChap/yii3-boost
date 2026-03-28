<?php

declare(strict_types=1);

namespace codechap\yii3boost\Command;

use codechap\yii3boost\Mcp\Search\GitHubGuideDownloader;
use codechap\yii3boost\Mcp\Search\MarkdownSectionParser;
use codechap\yii3boost\Mcp\Search\SearchIndexManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Aliases\Aliases;

/**
 * Update the search index and download the latest Yii3 guide.
 */
#[AsCommand(
    name: 'boost:update',
    description: 'Update Yii3 AI Boost search index and guidelines',
)]
final class UpdateCommand extends Command
{
    public function __construct(
        private readonly Aliases $aliases,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootPath = $this->aliases->get('@root');
        $runtimePath = $rootPath . '/runtime/boost';

        $output->writeln('<info>Yii3 AI Boost — Update</info>');
        $output->writeln('');

        // Download Yii3 guide
        $output->writeln('Downloading Yii3 guide from GitHub...');
        $cachePath = $runtimePath . '/guide-cache';
        $downloader = new GitHubGuideDownloader($cachePath);
        $result = $downloader->download();

        $output->writeln("  Downloaded: {$result['downloaded']}, Skipped: {$result['skipped']}, Failed: {$result['failed']}");

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $output->writeln("  <error>$error</error>");
            }
        }

        // Rebuild search index
        $output->writeln('');
        $output->writeln('Rebuilding search index...');

        $dbPath = $runtimePath . '/search.db';
        $indexManager = new SearchIndexManager($dbPath);
        $indexManager->createSchema();
        $indexManager->clearIndex();

        $parser = new MarkdownSectionParser();
        $totalSections = 0;

        // Index bundled guidelines
        $guidelinesPath = dirname(__DIR__, 2) . '/.ai/guidelines';
        if (is_dir($guidelinesPath)) {
            $files = glob($guidelinesPath . '/**/*.md') ?: [];
            $files = array_merge($files, glob($guidelinesPath . '/*.md') ?: []);

            foreach ($files as $file) {
                $content = file_get_contents($file);
                $parsed = $parser->parse($content, $file);
                $category = basename(dirname($file));
                if ($category === 'guidelines') {
                    $category = 'general';
                }

                $count = $indexManager->indexSections(
                    'bundled',
                    $category,
                    $file,
                    $parsed['file_title'],
                    $parsed['sections'],
                );
                $totalSections += $count;
            }
        }

        // Index downloaded guide
        $cachedFiles = $downloader->getCachedFiles();
        foreach ($cachedFiles as $file) {
            $content = file_get_contents($file);
            $parsed = $parser->parse($content, $file);
            $category = GitHubGuideDownloader::mapCategory(basename($file));

            $count = $indexManager->indexSections(
                'yii3_guide',
                $category,
                $file,
                $parsed['file_title'],
                $parsed['sections'],
            );
            $totalSections += $count;
        }

        $indexManager->setMeta('last_rebuild', date('Y-m-d H:i:s'));
        $indexManager->setMeta('section_count', (string) $totalSections);

        $output->writeln("  Indexed $totalSections sections");
        $output->writeln('');
        $output->writeln('<info>Update complete.</info>');

        return Command::SUCCESS;
    }
}
