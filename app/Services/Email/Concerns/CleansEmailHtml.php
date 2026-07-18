<?php

namespace App\Services\Email\Concerns;

trait CleansEmailHtml
{
    /**
     * Clean HTML while preserving structural information that helps Claude
     * understand product tables, line items, prices, etc.
     */
    public function cleanHtml(string $html): string
    {
        // Remove style and script tags entirely
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);

        // Convert table cells and rows to readable format
        $html = preg_replace('/<\/td>/i', ' | ', $html);
        $html = preg_replace('/<\/tr>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Truncate to ~8000 chars — larger limit catches order items in emails
        // that have verbose marketing/header content before the actual line items.
        // Claude Sonnet handles this token count easily within the 2000 output limit.
        if (strlen($text) > 8000) {
            $text = substr($text, 0, 8000)."\n...[truncated]";
        }

        return $text;
    }
}
