import { PropsWithChildren } from 'react';
import { Head, usePage } from '@inertiajs/react';
import Navbar from '@/Components/Marketing/Navbar';
import Footer from '@/Components/Marketing/Footer';
import JsonLd from '@/Components/JsonLd';

interface BreadcrumbItem {
    name: string;
    url: string;
}

interface PublicLayoutProps {
    title?: string;
    description?: string;
    canonical?: string;
    ogImage?: string;
    breadcrumbs?: BreadcrumbItem[];
}

const SITE_URL = 'https://ledgeriq.com';
const DEFAULT_OG_IMAGE = `${SITE_URL}/images/ledgeriq-og.png`;
const DEFAULT_DESCRIPTION = 'AI-powered expense tracking that automatically categorizes transactions, detects unused subscriptions, finds savings, and prepares your tax deductions. 100% free.';

const organizationSchema = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    '@id': `${SITE_URL}/#organization`,
    name: 'LedgerIQ',
    url: SITE_URL,
    logo: {
        '@type': 'ImageObject',
        url: `${SITE_URL}/images/ledgeriq-icon.png`,
        width: 512,
        height: 512,
    },
    description: DEFAULT_DESCRIPTION,
    email: 'support@ledgeriq.com',
    contactPoint: {
        '@type': 'ContactPoint',
        contactType: 'customer support',
        email: 'support@ledgeriq.com',
        url: `${SITE_URL}/contact`,
    },
};

const websiteSchema = {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    '@id': `${SITE_URL}/#website`,
    name: 'LedgerIQ',
    url: SITE_URL,
    publisher: { '@id': `${SITE_URL}/#organization` },
};

export default function PublicLayout({
    title,
    description,
    canonical,
    ogImage,
    breadcrumbs,
    children,
}: PropsWithChildren<PublicLayoutProps>) {
    const { url } = usePage();
    const metaDescription = description || DEFAULT_DESCRIPTION;
    const canonicalUrl = canonical || `${SITE_URL}${url}`;
    const ogImg = ogImage || DEFAULT_OG_IMAGE;
    const fullTitle = title ? `${title} - LedgerIQ` : 'LedgerIQ - AI-Powered Expense Tracking';

    const breadcrumbSchema = breadcrumbs?.length
        ? {
              '@context': 'https://schema.org',
              '@type': 'BreadcrumbList',
              itemListElement: [
                  { '@type': 'ListItem', position: 1, name: 'Home', item: SITE_URL },
                  ...breadcrumbs.map((crumb, i) => ({
                      '@type': 'ListItem',
                      position: i + 2,
                      name: crumb.name,
                      item: `${SITE_URL}${crumb.url}`,
                  })),
              ],
          }
        : null;

    return (
        <>
            <Head title={title}>
                <meta name="description" content={metaDescription} />
                <link rel="canonical" href={canonicalUrl} />

                {/* Open Graph */}
                <meta property="og:type" content="website" />
                <meta property="og:site_name" content="LedgerIQ" />
                <meta property="og:title" content={fullTitle} />
                <meta property="og:description" content={metaDescription} />
                <meta property="og:url" content={canonicalUrl} />
                <meta property="og:image" content={ogImg} />
                <meta property="og:image:width" content="1200" />
                <meta property="og:image:height" content="630" />
                <meta property="og:image:alt" content={`${title || 'LedgerIQ'} - AI Expense Tracking`} />

                {/* Twitter Card */}
                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:title" content={fullTitle} />
                <meta name="twitter:description" content={metaDescription} />
                <meta name="twitter:image" content={ogImg} />
            </Head>
            <JsonLd data={organizationSchema} />
            <JsonLd data={websiteSchema} />
            {breadcrumbSchema && <JsonLd data={breadcrumbSchema} />}
            <div className="flex min-h-screen flex-col bg-white">
                <Navbar />
                <main className="flex-1">{children}</main>
                <Footer />
            </div>
        </>
    );
}
