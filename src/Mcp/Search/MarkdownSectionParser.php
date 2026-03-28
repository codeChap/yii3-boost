<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Search;

/**
 * Parses markdown files into sections split on H2 headings.
 *
 * Each section contains the H2 title and the body text below it,
 * up to the next H2 heading or end of file.
 */
final class MarkdownSectionParser
{
    /**
     * @return array{file_title: string, sections: array<int, array{section_title: string, body: string}>}
     */
    public function parse(string $markdown, string $filePath = ''): array
    {
        $lines = explode("\n", $markdown);
        $fileTitle = basename($filePath, '.md');
        $sections = [];
        $currentSection = null;
        $bodyLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/', $line, $matches)) {
                $fileTitle = trim($matches[1]);
                continue;
            }

            if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
                if ($currentSection !== null) {
                    $sections[] = [
                        'section_title' => $currentSection,
                        'body' => $this->trimBody($bodyLines),
                    ];
                } elseif (!empty($bodyLines)) {
                    $body = $this->trimBody($bodyLines);
                    if ($body !== '') {
                        $sections[] = [
                            'section_title' => 'Introduction',
                            'body' => $body,
                        ];
                    }
                }

                $currentSection = trim($matches[1]);
                $bodyLines = [];
                continue;
            }

            $bodyLines[] = $line;
        }

        if ($currentSection !== null) {
            $sections[] = [
                'section_title' => $currentSection,
                'body' => $this->trimBody($bodyLines),
            ];
        } elseif (!empty($bodyLines)) {
            $body = $this->trimBody($bodyLines);
            if ($body !== '') {
                $sections[] = [
                    'section_title' => 'Content',
                    'body' => $body,
                ];
            }
        }

        return [
            'file_title' => $fileTitle,
            'sections' => $sections,
        ];
    }

    private function trimBody(array $lines): string
    {
        while (!empty($lines) && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while (!empty($lines) && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }
        return implode("\n", $lines);
    }
}
