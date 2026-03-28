<?php

declare(strict_types=1);

namespace codechap\yii3boost\tests\Mcp\Search;

use codechap\yii3boost\Mcp\Search\MarkdownSectionParser;
use PHPUnit\Framework\TestCase;

class MarkdownSectionParserTest extends TestCase
{
    private MarkdownSectionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MarkdownSectionParser();
    }

    public function testParseWithH1AndH2(): void
    {
        $md = <<<'MD'
# Guide Title

## First Section

First body content.

## Second Section

Second body content.
MD;

        $result = $this->parser->parse($md, 'guide.md');

        $this->assertSame('Guide Title', $result['file_title']);
        $this->assertCount(2, $result['sections']);

        $this->assertSame('First Section', $result['sections'][0]['section_title']);
        $this->assertSame('First body content.', $result['sections'][0]['body']);

        $this->assertSame('Second Section', $result['sections'][1]['section_title']);
        $this->assertSame('Second body content.', $result['sections'][1]['body']);
    }

    public function testParseWithoutH2(): void
    {
        $md = <<<'MD'
# Simple Document

Just a single block of content with no subsections.

Some more text here.
MD;

        $result = $this->parser->parse($md, 'simple.md');

        $this->assertSame('Simple Document', $result['file_title']);
        $this->assertCount(1, $result['sections']);
        $this->assertSame('Content', $result['sections'][0]['section_title']);
        $this->assertStringContainsString('single block of content', $result['sections'][0]['body']);
    }

    public function testParseIntroductionBeforeFirstH2(): void
    {
        $md = <<<'MD'
# Title

This is introductory text before any section heading.

## First Section

Section body content.
MD;

        $result = $this->parser->parse($md, 'test.md');

        $this->assertSame('Title', $result['file_title']);
        $this->assertCount(2, $result['sections']);
        $this->assertSame('Introduction', $result['sections'][0]['section_title']);
        $this->assertSame('This is introductory text before any section heading.', $result['sections'][0]['body']);
        $this->assertSame('First Section', $result['sections'][1]['section_title']);
        $this->assertSame('Section body content.', $result['sections'][1]['body']);
    }
}
