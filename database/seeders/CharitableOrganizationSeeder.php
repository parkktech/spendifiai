<?php

namespace Database\Seeders;

use App\Models\CharitableOrganization;
use Illuminate\Database\Seeder;

class CharitableOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $orgs = [
            // Religious / Jewish
            [
                'name' => 'Chabad.org',
                'description' => 'Jewish education, outreach, and social services worldwide',
                'website_url' => 'https://www.chabad.org',
                'donate_url' => 'https://www.chabad.org/donations/default_cdo/jewish/Donate.htm',
                'category' => 'Religious',
                'ein' => '11-3015882',
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Jewish Federation of North America',
                'description' => 'Supports Jewish communities and social welfare across North America and globally',
                'website_url' => 'https://www.jewishfederations.org',
                'donate_url' => 'https://www.jewishfederations.org/donate',
                'category' => 'Religious',
                'ein' => '13-1624241',
                'is_featured' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Hadassah',
                'description' => 'Women\'s organization supporting medical care and research in Israel',
                'website_url' => 'https://www.hadassah.org',
                'donate_url' => 'https://www.hadassah.org/donate/',
                'category' => 'Religious',
                'ein' => '13-1656671',
                'is_featured' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'American Friends of Magen David Adom',
                'description' => 'Israel\'s national emergency medical, disaster, ambulance, and blood bank service',
                'website_url' => 'https://www.afmda.org',
                'donate_url' => 'https://www.afmda.org/donate/',
                'category' => 'Religious',
                'ein' => '13-2648484',
                'is_featured' => true,
                'sort_order' => 4,
            ],

            // Humanitarian
            [
                'name' => 'American Red Cross',
                'description' => 'Disaster relief, blood donations, health and safety training, and military family support',
                'website_url' => 'https://www.redcross.org',
                'donate_url' => 'https://www.redcross.org/donate/donation.html/',
                'category' => 'Humanitarian',
                'ein' => '53-0196605',
                'is_featured' => true,
                'sort_order' => 5,
            ],
            [
                'name' => 'The Salvation Army',
                'description' => 'Poverty relief, disaster assistance, and community programs nationwide',
                'website_url' => 'https://www.salvationarmyusa.org',
                'donate_url' => 'https://www.salvationarmyusa.org/usn/ways-to-give/',
                'category' => 'Humanitarian',
                'ein' => '13-5562351',
                'is_featured' => true,
                'sort_order' => 6,
            ],
            [
                'name' => 'United Way',
                'description' => 'Community-based solutions for education, financial stability, and health',
                'website_url' => 'https://www.unitedway.org',
                'donate_url' => 'https://www.unitedway.org/get-involved/ways-to-give',
                'category' => 'Humanitarian',
                'ein' => '13-1635294',
                'is_featured' => true,
                'sort_order' => 7,
            ],
            [
                'name' => 'Feeding America',
                'description' => 'Nationwide network of food banks fighting hunger and food insecurity',
                'website_url' => 'https://www.feedingamerica.org',
                'donate_url' => 'https://www.feedingamerica.org/ways-to-give',
                'category' => 'Humanitarian',
                'ein' => '36-3673599',
                'is_featured' => true,
                'sort_order' => 8,
            ],
            [
                'name' => 'Habitat for Humanity',
                'description' => 'Builds affordable housing for families in need around the world',
                'website_url' => 'https://www.habitat.org',
                'donate_url' => 'https://www.habitat.org/donate/',
                'category' => 'Humanitarian',
                'ein' => '91-1914868',
                'is_featured' => true,
                'sort_order' => 9,
            ],

            // Health
            [
                'name' => 'St. Jude Children\'s Research Hospital',
                'description' => 'Leading children\'s hospital pioneering research and treatment for pediatric diseases',
                'website_url' => 'https://www.stjude.org',
                'donate_url' => 'https://www.stjude.org/donate/donate-to-st-jude.html',
                'category' => 'Health',
                'ein' => '62-0646012',
                'is_featured' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'American Cancer Society',
                'description' => 'Cancer research, patient support, early detection, and treatment access',
                'website_url' => 'https://www.cancer.org',
                'donate_url' => 'https://donate.cancer.org/',
                'category' => 'Health',
                'ein' => '13-1788491',
                'is_featured' => true,
                'sort_order' => 11,
            ],

            // Education
            [
                'name' => 'UNCF (United Negro College Fund)',
                'description' => 'Scholarships and support for students at historically Black colleges and universities',
                'website_url' => 'https://uncf.org',
                'donate_url' => 'https://uncf.org/donate',
                'category' => 'Education',
                'ein' => '13-1624241',
                'is_featured' => true,
                'sort_order' => 12,
            ],
            [
                'name' => 'DonorsChoose',
                'description' => 'Fund classroom projects and supplies for public school teachers across America',
                'website_url' => 'https://www.donorschoose.org',
                'donate_url' => 'https://www.donorschoose.org/donors/giving',
                'category' => 'Education',
                'ein' => '13-4129457',
                'is_featured' => true,
                'sort_order' => 13,
            ],

            // Environment
            [
                'name' => 'The Nature Conservancy',
                'description' => 'Protects lands and waters across the globe for nature and people',
                'website_url' => 'https://www.nature.org',
                'donate_url' => 'https://www.nature.org/en-us/membership-and-giving/',
                'category' => 'Environment',
                'ein' => '53-0242652',
                'is_featured' => true,
                'sort_order' => 14,
            ],
            [
                'name' => 'Sierra Club Foundation',
                'description' => 'Grassroots environmental organization promoting clean energy and conservation',
                'website_url' => 'https://www.sierraclub.org',
                'donate_url' => 'https://www.sierraclub.org/ways-give',
                'category' => 'Environment',
                'ein' => '94-6069890',
                'is_featured' => true,
                'sort_order' => 15,
            ],

            // Community
            [
                'name' => 'Goodwill Industries',
                'description' => 'Job training, employment placement, and community programs for people with barriers',
                'website_url' => 'https://www.goodwill.org',
                'donate_url' => 'https://www.goodwill.org/donate/financial-donation/',
                'category' => 'Community',
                'ein' => '53-0196517',
                'is_featured' => true,
                'sort_order' => 16,
            ],
            [
                'name' => 'Boys & Girls Clubs of America',
                'description' => 'After-school programs empowering youth to reach their full potential',
                'website_url' => 'https://www.bgca.org',
                'donate_url' => 'https://www.bgca.org/ways-to-give',
                'category' => 'Community',
                'ein' => '13-5562976',
                'is_featured' => true,
                'sort_order' => 17,
            ],
            [
                'name' => 'NPR (National Public Radio)',
                'description' => 'Independent, nonprofit media organization delivering news and cultural programming',
                'website_url' => 'https://www.npr.org',
                'donate_url' => 'https://www.npr.org/donations/support',
                'category' => 'Community',
                'ein' => '52-1215587',
                'is_featured' => true,
                'sort_order' => 18,
            ],

            // Animal Welfare
            [
                'name' => 'ASPCA',
                'description' => 'Prevention of cruelty to animals through rescue, adoption, and advocacy',
                'website_url' => 'https://www.aspca.org',
                'donate_url' => 'https://www.aspca.org/ways-to-give',
                'category' => 'Animal Welfare',
                'ein' => '13-1623829',
                'is_featured' => true,
                'sort_order' => 19,
            ],
        ];

        foreach ($orgs as $org) {
            CharitableOrganization::updateOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($org['name'])],
                array_merge($org, ['is_active' => true])
            );
        }
    }
}
