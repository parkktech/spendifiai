<?php

namespace App\Console\Commands;

use App\Models\SeoPage;
use Illuminate\Console\Command;

class EnhanceSeoContent extends Command
{
    protected $signature = 'seo:enhance-content {--dry-run : Show changes without saving}';

    protected $description = 'Enhance blog content HTML formatting for better readability';

    public function handle(): int
    {
        $pages = SeoPage::all();
        $updated = 0;

        foreach ($pages as $page) {
            $original = $page->content;
            $enhanced = $this->enhanceContent($original, $page->category);

            if ($enhanced !== $original) {
                $updated++;
                $this->info("Enhanced: {$page->slug}");

                if (! $this->option('dry-run')) {
                    $page->update(['content' => $enhanced]);
                }
            }
        }

        $this->info("Done. Enhanced {$updated} of {$pages->count()} pages.".($this->option('dry-run') ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    private function enhanceContent(string $html, string $category): string
    {
        // 1. Split long paragraphs (>400 chars) at natural sentence boundaries
        $html = $this->splitLongParagraphs($html);

        // 2. Convert tip/note/important paragraphs into blockquotes
        $html = $this->convertTipsToBlockquotes($html);

        // 3. Wrap CTA paragraphs in styled boxes
        $html = $this->wrapCtaParagraphs($html);

        // 4. Add key-takeaway wrapper to verdict/bottom-line/conclusion sections
        $html = $this->wrapVerdictSections($html);

        // 5. Wrap standalone stat paragraphs in highlight boxes
        $html = $this->wrapStatHighlights($html);

        // 6. Clean up: normalize spacing, remove double-blank-lines
        $html = $this->cleanupSpacing($html);

        return $html;
    }

    private function splitLongParagraphs(string $html): string
    {
        return preg_replace_callback('/<p>(.*?)<\/p>/s', function ($match) {
            $text = $match[1];
            $length = strlen(strip_tags($text));

            // Only split if >400 chars and has multiple sentences
            if ($length <= 400) {
                return $match[0];
            }

            // Find a good split point — after a sentence ending near the middle
            $stripped = strip_tags($text);
            $midpoint = (int) ($length * 0.45);

            // Find sentence breaks (period/question mark/exclamation + space + capital)
            $breaks = [];
            preg_match_all('/[.!?]\s+(?=[A-Z])/', $stripped, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as $breakMatch) {
                $pos = $breakMatch[1] + strlen($breakMatch[0]);
                if ($pos > 80 && $pos < $length - 80) {
                    $breaks[] = $pos;
                }
            }

            if (empty($breaks)) {
                return $match[0];
            }

            // Pick the break closest to the midpoint
            $bestBreak = $breaks[0];
            $bestDist = abs($breaks[0] - $midpoint);
            foreach ($breaks as $b) {
                $dist = abs($b - $midpoint);
                if ($dist < $bestDist) {
                    $bestBreak = $b;
                    $bestDist = $dist;
                }
            }

            // Now map the stripped-text position back to the HTML position
            $htmlPos = $this->mapStrippedPosToHtml($text, $bestBreak);
            if ($htmlPos === null) {
                return $match[0];
            }

            $part1 = substr($text, 0, $htmlPos);
            $part2 = ltrim(substr($text, $htmlPos));

            // Validate both parts have actual content
            if (strlen(strip_tags($part1)) < 50 || strlen(strip_tags($part2)) < 50) {
                return $match[0];
            }

            return "<p>{$part1}</p>\n\n<p>{$part2}</p>";
        }, $html);
    }

    /**
     * Map a position in stripped text back to the HTML position.
     */
    private function mapStrippedPosToHtml(string $html, int $strippedPos): ?int
    {
        $htmlLen = strlen($html);
        $stripped = 0;
        $inTag = false;

        for ($i = 0; $i < $htmlLen; $i++) {
            if ($html[$i] === '<') {
                $inTag = true;

                continue;
            }
            if ($html[$i] === '>') {
                $inTag = false;

                continue;
            }
            if (! $inTag) {
                $stripped++;
                if ($stripped >= $strippedPos) {
                    return $i + 1;
                }
            }
        }

        return null;
    }

    private function convertTipsToBlockquotes(string $html): string
    {
        // Convert <p><strong>Tip:</strong>...</p> and similar patterns
        $patterns = [
            // Already has strong wrapper
            '/<p><strong>(Tip|Pro tip|Pro Tip|Note|Important|Key insight|Key Insight|Remember|Did you know|Warning)[\s:]*<\/strong>/i',
            // Plain text start
            '/<p>(Tip|Pro tip|Pro Tip|Note|Important|Key insight|Key Insight|Remember|Warning):\s*/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace_callback($pattern, function ($match) {
                // Extract the label
                $label = $match[1] ?? 'Tip';
                $label = ucfirst(strtolower($label));

                // Check if it already has strong
                if (str_contains($match[0], '<strong>')) {
                    return '<blockquote><p><strong>'.$label.':</strong>';
                }

                return '<blockquote><p><strong>'.$label.':</strong> ';
            }, $html);
        }

        // Close any opened blockquotes — find <blockquote><p>.....</p> and close with </blockquote>
        $html = preg_replace('/<blockquote><p>(.*?)<\/p>/s', '<blockquote><p>$1</p></blockquote>', $html);

        // Remove double-blockquote wrapping
        $html = str_replace('<blockquote><blockquote>', '<blockquote>', $html);
        $html = str_replace('</blockquote></blockquote>', '</blockquote>', $html);

        return $html;
    }

    private function wrapCtaParagraphs(string $html): string
    {
        // Wrap paragraphs that are CTAs (contain register/features links and "ready to" or "get started" or "try SpendifiAI")
        return preg_replace_callback('/<p>(.*?)<\/p>/s', function ($match) {
            $text = $match[1];
            $hasLink = str_contains($text, 'href="/register"') || str_contains($text, 'href="/features"');
            $isCta = preg_match('/(Ready to|Get started|Get SpendifiAI|Sign up|Create your free|Start tracking)/i', $text);

            if ($hasLink && $isCta) {
                return '<div class="inline-cta"><p>'.$text.'</p></div>';
            }

            return $match[0];
        }, $html);
    }

    private function wrapVerdictSections(string $html): string
    {
        // Find <h2>Verdict</h2> or <h2>The Bottom Line</h2> or <h2>Conclusion</h2>
        // and wrap the following paragraph in key-takeaway
        $patterns = ['Verdict', 'The Bottom Line', 'Conclusion', 'Final Verdict', 'Our Recommendation', 'The Takeaway'];

        foreach ($patterns as $heading) {
            $searchH2 = '<h2>'.$heading.'</h2>';
            if (str_contains($html, $searchH2)) {
                // Wrap the paragraph(s) after this heading
                $html = preg_replace(
                    '/'.preg_quote($searchH2, '/').'\s*<p>/s',
                    $searchH2."\n".'<div class="key-takeaway"><p>',
                    $html,
                    1
                );
                // Close the div after the first </p> following the key-takeaway opening
                $pos = strpos($html, '<div class="key-takeaway"><p>');
                if ($pos !== false) {
                    $closeP = strpos($html, '</p>', $pos + 30);
                    if ($closeP !== false) {
                        $html = substr($html, 0, $closeP + 4).'</div>'.substr($html, $closeP + 4);
                    }
                }
            }
        }

        return $html;
    }

    private function wrapStatHighlights(string $html): string
    {
        // Find short paragraphs (<200 chars) that are mostly stats/numbers
        return preg_replace_callback('/<p>(.*?)<\/p>/s', function ($match) {
            $text = strip_tags($match[1]);
            $length = strlen($text);

            // Only target short-ish paragraphs with high number density
            if ($length > 200 || $length < 30) {
                return $match[0];
            }

            // Count numeric patterns ($X, X%, X months, etc.)
            $statMatches = preg_match_all('/(\$[\d,.]+|[\d,.]+%|\d+ months?|\d+ years?|\d+ days?|\d+ hours?|\d+x)/i', $text);

            // High stat density: at least 2 stats in a short paragraph
            if ($statMatches >= 2 && $length < 150) {
                return '<div class="stat-highlight"><p>'.$match[1].'</p></div>';
            }

            return $match[0];
        }, $html);
    }

    private function cleanupSpacing(string $html): string
    {
        // Normalize multiple newlines
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        // Ensure blank line before <h2> and <h3>
        $html = preg_replace('/([^\n])\n?(<h[23])/', "$1\n\n$2", $html);

        // Ensure blank line after closing tags before new blocks
        $html = preg_replace('/(<\/(?:ul|ol|table|blockquote|div)>)\s*(<(?:h[23]|p|ul|ol))/', "$1\n\n$2", $html);

        return trim($html);
    }
}
