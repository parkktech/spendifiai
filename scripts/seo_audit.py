#!/usr/bin/env python3
"""SEO audit script - captures screenshots and extracts page metadata."""

from playwright.sync_api import sync_playwright
import json
import os
import re

SCREENSHOTS_DIR = "/var/www/html/spendifiai/screenshots"

PAGES = [
    {"url": "http://www.spendifiai.loc/", "name": "homepage"},
    {"url": "http://www.spendifiai.loc/features", "name": "features"},
    {"url": "http://www.spendifiai.loc/how-it-works", "name": "how-it-works"},
    {"url": "http://www.spendifiai.loc/about", "name": "about"},
    {"url": "http://www.spendifiai.loc/faq", "name": "faq"},
    {"url": "http://www.spendifiai.loc/blog", "name": "blog-index"},
    {"url": "http://www.spendifiai.loc/blog/tax", "name": "blog-tax"},
]

def extract_page_data(page):
    """Extract comprehensive SEO data from a page."""
    data = page.evaluate(r"""() => {
        const title = document.title || '';
        const metaDesc = document.querySelector('meta[name="description"]')?.content || '';
        const metaKeywords = document.querySelector('meta[name="keywords"]')?.content || '';
        const canonical = document.querySelector('link[rel="canonical"]')?.href || '';
        const ogTitle = document.querySelector('meta[property="og:title"]')?.content || '';
        const ogDesc = document.querySelector('meta[property="og:description"]')?.content || '';
        const ogImage = document.querySelector('meta[property="og:image"]')?.content || '';
        const ogType = document.querySelector('meta[property="og:type"]')?.content || '';
        const twitterCard = document.querySelector('meta[name="twitter:card"]')?.content || '';
        const robots = document.querySelector('meta[name="robots"]')?.content || '';

        const h1s = Array.from(document.querySelectorAll('h1')).map(h => h.textContent.trim());
        const h2s = Array.from(document.querySelectorAll('h2')).map(h => h.textContent.trim());
        const h3s = Array.from(document.querySelectorAll('h3')).map(h => h.textContent.trim());
        const h4s = Array.from(document.querySelectorAll('h4')).map(h => h.textContent.trim());

        const links = Array.from(document.querySelectorAll('a'));
        const internalLinks = links.filter(a => {
            const href = a.href || '';
            return href.includes('spendifiai.loc') || (href.startsWith('/') && !href.startsWith('//'));
        }).map(a => ({
            href: a.href,
            text: a.textContent.trim().substring(0, 100),
            isNav: !!(a.closest('nav') || a.closest('header') || a.closest('footer'))
        }));

        const externalLinks = links.filter(a => {
            const href = a.href || '';
            return href.startsWith('http') && !href.includes('spendifiai.loc');
        }).map(a => ({
            href: a.href,
            text: a.textContent.trim().substring(0, 100),
            hasNofollow: a.rel?.includes('nofollow') || false,
            hasTarget: a.target === '_blank'
        }));

        // Word count
        const bodyText = document.body?.innerText || '';
        const wordCount = bodyText.split(/\s+/).filter(w => w.length > 0).length;

        // Images
        const images = Array.from(document.querySelectorAll('img')).map(img => ({
            src: img.src?.substring(0, 200),
            alt: img.alt || '',
            hasAlt: !!img.alt,
            width: img.naturalWidth,
            height: img.naturalHeight
        }));

        // Schema/structured data
        const schemas = Array.from(document.querySelectorAll('script[type="application/ld+json"]'))
            .map(s => {
                try { return JSON.parse(s.textContent); }
                catch { return null; }
            }).filter(Boolean);

        // CTAs - broader selector
        const ctas = Array.from(document.querySelectorAll('button, a.btn, a[class*="btn"], a[class*="button"], a[class*="cta"], a[class*="CTA"], [role="button"]'))
            .map(b => ({
                text: b.textContent.trim().substring(0, 100),
                tag: b.tagName,
                href: b.href || '',
                classes: b.className?.substring?.(0, 200) || ''
            }));

        // All anchor-styled CTAs (links with action-oriented text)
        const actionLinks = links.filter(a => {
            const text = (a.textContent || '').toLowerCase().trim();
            return text.includes('start') || text.includes('sign up') || text.includes('get') ||
                   text.includes('try') || text.includes('free') || text.includes('register') ||
                   text.includes('learn more') || text.includes('see') || text.includes('explore');
        }).map(a => ({
            text: a.textContent.trim().substring(0, 100),
            href: a.href || '',
            classes: a.className?.substring?.(0, 200) || ''
        }));

        // Author info
        const authorElements = Array.from(document.querySelectorAll('[class*="author"], [rel="author"], [itemprop="author"], .author, .by-line, .byline'))
            .map(a => a.textContent.trim().substring(0, 200));

        // Date/freshness
        const dateElements = Array.from(document.querySelectorAll('time, [datetime], [class*="date"], [class*="publish"], [itemprop="datePublished"], [itemprop="dateModified"]'))
            .map(d => ({
                text: d.textContent.trim().substring(0, 100),
                datetime: d.getAttribute('datetime') || ''
            }));

        // Paragraphs
        const paragraphs = Array.from(document.querySelectorAll('p')).map(p => p.textContent.trim()).filter(t => t.length > 20);

        // Lists
        const lists = document.querySelectorAll('ul, ol').length;
        const listItems = document.querySelectorAll('li').length;

        // Trust signals
        const trustSignals = [];
        const allText = bodyText.toLowerCase();
        if (allText.includes('bank-level') || allText.includes('256-bit') || allText.includes('encryption')) trustSignals.push('encryption_mentioned');
        if (allText.includes('secure') || allText.includes('security')) trustSignals.push('security_mentioned');
        if (allText.includes('privacy')) trustSignals.push('privacy_mentioned');
        if (allText.includes('testimonial') || allText.includes('review')) trustSignals.push('social_proof');
        if (allText.includes('fdic') || allText.includes('soc 2') || allText.includes('compliant')) trustSignals.push('compliance_mentioned');
        if (allText.includes('free') || allText.includes('no credit card')) trustSignals.push('low_risk_offer');

        // Main content text for analysis
        const mainContent = document.querySelector('main')?.innerText || bodyText;

        return {
            title, metaDesc, metaKeywords, canonical,
            ogTitle, ogDesc, ogImage, ogType, twitterCard, robots,
            h1s, h2s, h3s, h4s,
            internalLinks, externalLinks,
            wordCount,
            images,
            schemas,
            ctas, actionLinks,
            authorElements,
            dateElements,
            paragraphs: paragraphs.length,
            paragraphTexts: paragraphs.slice(0, 15),
            lists, listItems,
            trustSignals,
            mainContent: mainContent.substring(0, 8000),
            bodyText: bodyText.substring(0, 10000)
        };
    }""")
    return data


def audit_page(browser, page_info, viewport_width=1920, viewport_height=1080):
    """Capture screenshot and extract data for a single page."""
    context = browser.new_context(viewport={'width': viewport_width, 'height': viewport_height})
    page = context.new_page()

    try:
        response = page.goto(page_info['url'], wait_until='networkidle', timeout=15000)
        status = response.status if response else 0

        # Desktop screenshot (above the fold)
        desktop_path = f"{SCREENSHOTS_DIR}/{page_info['name']}_desktop.png"
        page.screenshot(path=desktop_path, full_page=False)

        # Full page screenshot
        full_path = f"{SCREENSHOTS_DIR}/{page_info['name']}_full.png"
        page.screenshot(path=full_path, full_page=True)

        # Extract data
        data = extract_page_data(page)
        data['status_code'] = status
        data['url'] = page_info['url']
        data['name'] = page_info['name']

        context.close()

        # Mobile screenshot
        mobile_context = browser.new_context(viewport={'width': 375, 'height': 812})
        mobile_page = mobile_context.new_page()
        mobile_page.goto(page_info['url'], wait_until='networkidle', timeout=15000)
        mobile_path = f"{SCREENSHOTS_DIR}/{page_info['name']}_mobile.png"
        mobile_page.screenshot(path=mobile_path, full_page=False)
        mobile_full_path = f"{SCREENSHOTS_DIR}/{page_info['name']}_mobile_full.png"
        mobile_page.screenshot(path=mobile_full_path, full_page=True)
        mobile_context.close()

        return data
    except Exception as e:
        try:
            context.close()
        except:
            pass
        return {'error': str(e), 'url': page_info['url'], 'name': page_info['name']}


def discover_blog_articles(browser):
    """Find blog article links from the blog index and category pages."""
    articles = []
    for url in ["http://www.spendifiai.loc/blog", "http://www.spendifiai.loc/blog/tax"]:
        context = browser.new_context(viewport={'width': 1920, 'height': 1080})
        page = context.new_page()
        try:
            page.goto(url, wait_until='networkidle', timeout=15000)
            found = page.evaluate(r"""() => {
                const links = Array.from(document.querySelectorAll('a'));
                return links
                    .filter(a => {
                        const href = a.href || '';
                        // Match blog article patterns but exclude category/index pages
                        return href.includes('/blog/') &&
                               !href.endsWith('/blog/') &&
                               !href.endsWith('/blog') &&
                               href.split('/blog/')[1]?.includes('/');
                    })
                    .map(a => ({
                        url: a.href,
                        text: a.textContent.trim().substring(0, 200)
                    }));
            }""")
            articles.extend(found)
            context.close()
        except Exception as e:
            print(f"  Error discovering from {url}: {e}")
            try:
                context.close()
            except:
                pass

    # Also try without nested path requirement
    if not articles:
        context = browser.new_context(viewport={'width': 1920, 'height': 1080})
        page = context.new_page()
        try:
            page.goto("http://www.spendifiai.loc/blog", wait_until='networkidle', timeout=15000)
            found = page.evaluate(r"""() => {
                const links = Array.from(document.querySelectorAll('a'));
                const blogLinks = links.filter(a => {
                    const href = a.href || '';
                    const path = new URL(href, window.location.origin).pathname;
                    return path.startsWith('/blog/') &&
                           path !== '/blog/' &&
                           path.length > 6;
                }).map(a => ({
                    url: a.href,
                    text: a.textContent.trim().substring(0, 200)
                }));
                return blogLinks;
            }""")
            articles.extend(found)
            context.close()
        except Exception as e:
            print(f"  Error: {e}")
            try:
                context.close()
            except:
                pass

    # Deduplicate by URL
    seen = set()
    unique = []
    for a in articles:
        if a['url'] not in seen:
            seen.add(a['url'])
            unique.append(a)
    return unique


def main():
    with sync_playwright() as p:
        browser = p.chromium.launch()

        results = []

        # Audit main pages
        for page_info in PAGES:
            print(f"Auditing: {page_info['url']}")
            data = audit_page(browser, page_info)
            results.append(data)

        # Discover blog articles
        print("\nDiscovering blog articles...")
        articles = discover_blog_articles(browser)
        print(f"Found {len(articles)} unique blog article links")
        for a in articles[:10]:
            print(f"  - {a['url']}: {a['text'][:80]}")

        # Save article list
        with open(f"{SCREENSHOTS_DIR}/blog_articles.json", 'w') as f:
            json.dump(articles, f, indent=2)

        # Audit first 4 blog articles
        for i, article in enumerate(articles[:4]):
            path_parts = article['url'].rstrip('/').split('/')
            slug = path_parts[-1] if path_parts[-1] else f"article-{i}"
            page_info = {"url": article['url'], "name": f"blog-{slug}"}
            print(f"Auditing blog article: {article['url']}")
            data = audit_page(browser, page_info)
            results.append(data)

        browser.close()

        # Save all results
        output_path = f"{SCREENSHOTS_DIR}/seo_audit_data.json"
        with open(output_path, 'w') as f:
            json.dump(results, f, indent=2, default=str)

        print(f"\nAudit complete. Data saved to {output_path}")
        print(f"Screenshots saved to {SCREENSHOTS_DIR}/")

        # Print summary
        for r in results:
            if 'error' in r:
                print(f"  ERROR {r['name']}: {r['error'][:100]}")
            else:
                print(f"  {r['name']}: title='{r.get('title','')[:60]}' | words={r.get('wordCount',0)} | H1s={len(r.get('h1s',[]))} | status={r.get('status_code',0)}")


if __name__ == '__main__':
    main()
