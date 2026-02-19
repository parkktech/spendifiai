<?php

namespace Database\Seeders;

use App\Models\CancellationProvider;
use Illuminate\Database\Seeder;

class CancellationProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            // ─── Streaming ───
            ['company_name' => 'Netflix', 'aliases' => ['NETFLIX', 'NETFLIX.COM', 'NETFLIX INC'], 'cancellation_url' => 'https://www.netflix.com/cancelplan', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Hulu', 'aliases' => ['HULU', 'HULU.COM', 'HULU LLC'], 'cancellation_url' => 'https://secure.hulu.com/account', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Disney+', 'aliases' => ['DISNEY PLUS', 'DISNEYPLUS', 'DISNEY+'], 'cancellation_url' => 'https://www.disneyplus.com/account/subscription', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Peacock', 'aliases' => ['PEACOCK', 'PEACOCK TV', 'PEACOCKTV'], 'cancellation_url' => 'https://www.peacocktv.com/account/plans', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Paramount+', 'aliases' => ['PARAMOUNT', 'PARAMOUNT+', 'PARAMOUNT PLUS'], 'cancellation_url' => 'https://www.paramountplus.com/account/', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Max (HBO)', 'aliases' => ['HBO MAX', 'HBO', 'MAX.COM', 'MAX HBO'], 'cancellation_url' => 'https://www.max.com/account', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Crunchyroll', 'aliases' => ['CRUNCHYROLL', 'CRUNCHYROLL.COM'], 'cancellation_url' => 'https://www.crunchyroll.com/account/subscription', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Roku Channel', 'aliases' => ['ROKU', 'THE ROKU CHANNEL', 'ROKU CHANNEL', 'ROKU.COM'], 'cancellation_url' => 'https://my.roku.com/account/subscriptions', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'ESPN+', 'aliases' => ['ESPN PLUS', 'ESPN+', 'ESPN'], 'cancellation_url' => 'https://plus.espn.com/account/subscriptions', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Apple TV+', 'aliases' => ['APPLE TV', 'APPLE TV+'], 'cancellation_url' => 'https://support.apple.com/en-us/HT202039', 'difficulty' => 'medium', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'YouTube Premium', 'aliases' => ['YOUTUBE PREMIUM', 'YOUTUBE MUSIC', 'GOOGLE YOUTUBE'], 'cancellation_url' => 'https://www.youtube.com/paid_memberships', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Starz', 'aliases' => ['STARZ', 'STARZ.COM'], 'cancellation_url' => 'https://www.starz.com/account', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Shudder', 'aliases' => ['SHUDDER', 'SHUDDER.COM'], 'cancellation_url' => 'https://www.shudder.com/account', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Discovery+', 'aliases' => ['DISCOVERY PLUS', 'DISCOVERY+'], 'cancellation_url' => 'https://www.discoveryplus.com/account', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'AMC+', 'aliases' => ['AMC PLUS', 'AMC+', 'AMC NETWORKS'], 'cancellation_url' => 'https://www.amcplus.com/account', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'BritBox', 'aliases' => ['BRITBOX'], 'cancellation_url' => 'https://www.britbox.com/account', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Curiosity Stream', 'aliases' => ['CURIOSITY STREAM', 'CURIOSITYSTREAM'], 'cancellation_url' => 'https://curiositystream.com/settings', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'MUBI', 'aliases' => ['MUBI', 'MUBI.COM'], 'cancellation_url' => 'https://mubi.com/settings', 'difficulty' => 'easy', 'category' => 'Streaming', 'is_essential' => false, 'is_verified' => true],

            // ─── Music ───
            ['company_name' => 'Spotify', 'aliases' => ['SPOTIFY', 'SPOTIFY.COM', 'SPOTIFY USA'], 'cancellation_url' => 'https://www.spotify.com/account/subscription/', 'difficulty' => 'easy', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Apple Music', 'aliases' => ['APPLE MUSIC', 'APPLE.COM/BILL APPLE MUSIC'], 'cancellation_url' => 'https://support.apple.com/en-us/HT202039', 'difficulty' => 'medium', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Pandora', 'aliases' => ['PANDORA', 'PANDORA MUSIC', 'PANDORA.COM', 'PANDORA MEDIA'], 'cancellation_url' => 'https://www.pandora.com/account/manage', 'difficulty' => 'easy', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Tidal', 'aliases' => ['TIDAL', 'TIDAL.COM'], 'cancellation_url' => 'https://account.tidal.com/subscription', 'difficulty' => 'easy', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Amazon Music', 'aliases' => ['AMAZON MUSIC', 'AMZN DIGITAL MUSIC'], 'cancellation_url' => 'https://www.amazon.com/music/settings', 'difficulty' => 'medium', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'SiriusXM', 'aliases' => ['SIRIUSXM', 'SIRIUS XM', 'SIRIUS'], 'cancellation_url' => 'https://www.siriusxm.com/manage-subscription', 'difficulty' => 'hard', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Audible', 'aliases' => ['AUDIBLE', 'AUDIBLE.COM', 'AUDIBLE INC', 'AMZN DIGITAL AUDIBLE'], 'cancellation_url' => 'https://www.audible.com/account/cancel', 'difficulty' => 'medium', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Deezer', 'aliases' => ['DEEZER', 'DEEZER.COM'], 'cancellation_url' => 'https://www.deezer.com/account/subscription', 'difficulty' => 'easy', 'category' => 'Music', 'is_essential' => false, 'is_verified' => true],

            // ─── Software & SaaS ───
            ['company_name' => 'Adobe Creative Cloud', 'aliases' => ['ADOBE', 'ADOBE.COM', 'ADOBE SYSTEMS', 'ADOBE INC'], 'cancellation_url' => 'https://account.adobe.com/plans', 'difficulty' => 'hard', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Microsoft 365', 'aliases' => ['MICROSOFT', 'MICROSOFT 365', 'MSFT', 'MICROSOFT*'], 'cancellation_url' => 'https://account.microsoft.com/services', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Dropbox', 'aliases' => ['DROPBOX', 'DROPBOX.COM'], 'cancellation_url' => 'https://www.dropbox.com/account/plan', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Google One', 'aliases' => ['GOOGLE ONE', 'GOOGLE *ONE', 'GOOGLE STORAGE'], 'cancellation_url' => 'https://one.google.com/settings', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Google Workspace', 'aliases' => ['GOOGLE WORKSPACE', 'GOOGLE *WORKSPACE', 'GOOGLE GSUITE', 'GSUITE'], 'cancellation_url' => 'https://admin.google.com/ac/billing/subscriptions', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'iCloud+', 'aliases' => ['ICLOUD', 'APPLE.COM/BILL ICLOUD', 'ICLOUD+'], 'cancellation_url' => 'https://support.apple.com/en-us/HT207594', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'ChatGPT Plus', 'aliases' => ['OPENAI CHATGPT', 'CHATGPT', 'OPENAI *CHATGPT'], 'cancellation_url' => 'https://chat.openai.com/settings/subscription', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Claude Pro', 'aliases' => ['ANTHROPIC CLAUDE', 'CLAUDE PRO'], 'cancellation_url' => 'https://claude.ai/settings/billing', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Grok (xAI)', 'aliases' => ['XAI', 'X.AI', 'GROK', 'XAI LLC', 'X.AI LLC'], 'cancellation_url' => 'https://x.ai/account', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'GitHub', 'aliases' => ['GITHUB', 'GITHUB.COM', 'GITHUB INC'], 'cancellation_url' => 'https://github.com/settings/billing', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Notion', 'aliases' => ['NOTION', 'NOTION.SO', 'NOTION LABS'], 'cancellation_url' => 'https://www.notion.so/my-account', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => '1Password', 'aliases' => ['1PASSWORD', '1PASSWORD.COM', 'AGILEBITS'], 'cancellation_url' => 'https://my.1password.com/settings/billing', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Dashlane', 'aliases' => ['DASHLANE', 'DASHLANE.COM'], 'cancellation_url' => 'https://app.dashlane.com/subscription', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'LastPass', 'aliases' => ['LASTPASS', 'LASTPASS.COM'], 'cancellation_url' => 'https://lastpass.com/account.php', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Elementor', 'aliases' => ['ELEMENTOR', 'ELEMENTOR.COM'], 'cancellation_url' => 'https://my.elementor.com/subscriptions/', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Mailchimp', 'aliases' => ['MAILCHIMP', 'MAILCHIMP.COM', 'INTUIT MAILCHIMP'], 'cancellation_url' => 'https://admin.mailchimp.com/account/billing/pause-or-delete/', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Atlassian', 'aliases' => ['ATLASSIAN', 'ATLASSIAN.COM', 'JIRA'], 'cancellation_url' => 'https://admin.atlassian.com/billing', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Algolia', 'aliases' => ['ALGOLIA', 'ALGOLIA.COM'], 'cancellation_url' => 'https://dashboard.algolia.com/account/billing', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Envato', 'aliases' => ['ENVATO', 'ENVATO.COM', 'ENVATO ELEMENTS'], 'cancellation_url' => 'https://account.envato.com/subscriptions', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Canva', 'aliases' => ['CANVA', 'CANVA.COM'], 'cancellation_url' => 'https://www.canva.com/settings/billing-and-teams', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Grammarly', 'aliases' => ['GRAMMARLY', 'GRAMMARLY.COM'], 'cancellation_url' => 'https://account.grammarly.com/subscription', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Zoom', 'aliases' => ['ZOOM.US', 'ZOOM VIDEO'], 'cancellation_url' => 'https://zoom.us/account/billing', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Slack', 'aliases' => ['SLACK', 'SLACK.COM', 'SLACK TECHNOLOGIES'], 'cancellation_url' => 'https://slack.com/account/settings#billing', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],

            // ─── Apple Services ───
            ['company_name' => 'Apple Services', 'aliases' => ['APPLE.COM/BILL', 'APL*APPLE', 'APPLE.COM', 'APPLE'], 'cancellation_url' => 'https://support.apple.com/en-us/HT202039', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],

            // ─── VPN & Security ───
            ['company_name' => 'NordVPN', 'aliases' => ['NORDVPN', 'NORD VPN', 'NORDVPN.COM'], 'cancellation_url' => 'https://my.nordaccount.com/dashboard/nordvpn/', 'difficulty' => 'medium', 'category' => 'VPN & Security', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'ExpressVPN', 'aliases' => ['EXPRESSVPN', 'EXPRESS VPN'], 'cancellation_url' => 'https://www.expressvpn.com/subscriptions', 'difficulty' => 'medium', 'category' => 'VPN & Security', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'BTGuard', 'aliases' => ['BTGUARD', 'BTGUARD.COM'], 'cancellation_url' => 'https://btguard.com/login', 'difficulty' => 'easy', 'category' => 'VPN & Security', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Surfshark', 'aliases' => ['SURFSHARK', 'SURFSHARK.COM'], 'cancellation_url' => 'https://my.surfshark.com/account/billing', 'difficulty' => 'medium', 'category' => 'VPN & Security', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'ProtonVPN', 'aliases' => ['PROTONVPN', 'PROTON VPN', 'PROTON.ME'], 'cancellation_url' => 'https://account.proton.me/dashboard', 'difficulty' => 'easy', 'category' => 'VPN & Security', 'is_essential' => false, 'is_verified' => true],

            // ─── Finance & Credit ───
            ['company_name' => 'Experian', 'aliases' => ['EXPERIAN', 'EXPERIAN.COM'], 'cancellation_url' => 'https://www.experian.com/consumer/cancel', 'difficulty' => 'hard', 'category' => 'Finance', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'IdentityIQ', 'aliases' => ['IDENTITYIQ', 'IDENTITYIQ.COM', 'IDENTITY IQ'], 'cancellation_url' => 'https://www.identityiq.com/CancelAccount.aspx', 'difficulty' => 'hard', 'category' => 'Finance', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Rocket Money', 'aliases' => ['ROCKET MONEY', 'TRUEBILL', 'ROCKET MONEY INC'], 'cancellation_url' => 'https://app.rocketmoney.com/settings', 'difficulty' => 'easy', 'category' => 'Finance', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Coinbase One', 'aliases' => ['COINBASE', 'COINBASE.COM'], 'cancellation_url' => 'https://www.coinbase.com/settings/subscription', 'difficulty' => 'easy', 'category' => 'Finance', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'YNAB', 'aliases' => ['YNAB', 'YOUNEEDABUDGET', 'YOU NEED A BUDGET'], 'cancellation_url' => 'https://app.ynab.com/settings/subscription', 'difficulty' => 'easy', 'category' => 'Finance', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'TradingView', 'aliases' => ['TRADINGVIEW', 'TRADINGVIEW.COM'], 'cancellation_url' => 'https://www.tradingview.com/account/#billing', 'difficulty' => 'easy', 'category' => 'Finance', 'is_essential' => false, 'is_verified' => true],

            // ─── Fitness & Health ───
            ['company_name' => 'Planet Fitness', 'aliases' => ['PLANET FITNESS', 'PLT FITNESS', 'PLT*FITNESS'], 'cancellation_url' => 'https://www.planetfitness.com/about-planet-fitness/customer-service/cancel-membership', 'cancellation_phone' => '(844) 880-7180', 'difficulty' => 'hard', 'category' => 'Fitness', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Peloton', 'aliases' => ['PELOTON', 'PELOTON.COM', 'PELOTON INTERACTIVE'], 'cancellation_url' => 'https://members.onepeloton.com/profile/subscriptions', 'difficulty' => 'medium', 'category' => 'Fitness', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Headspace', 'aliases' => ['HEADSPACE', 'HEADSPACE.COM'], 'cancellation_url' => 'https://www.headspace.com/settings/subscription', 'difficulty' => 'easy', 'category' => 'Health', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Calm', 'aliases' => ['CALM', 'CALM.COM', 'CALM APP'], 'cancellation_url' => 'https://www.calm.com/account/subscription', 'difficulty' => 'easy', 'category' => 'Health', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Fitbit Premium', 'aliases' => ['FITBIT', 'FITBIT.COM', 'FITBIT PREMIUM'], 'cancellation_url' => 'https://www.fitbit.com/settings/subscription', 'difficulty' => 'easy', 'category' => 'Fitness', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Garmin Connect', 'aliases' => ['GARMIN', 'GARMIN.COM', 'GARMIN CONNECT'], 'cancellation_url' => 'https://www.garmin.com/account/subscription', 'difficulty' => 'easy', 'category' => 'Fitness', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Noom', 'aliases' => ['NOOM', 'NOOM.COM', 'NOOM INC'], 'cancellation_url' => 'https://www.noom.com/support', 'cancellation_phone' => '(888) 507-1177', 'difficulty' => 'hard', 'category' => 'Health', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Strava', 'aliases' => ['STRAVA', 'STRAVA.COM'], 'cancellation_url' => 'https://www.strava.com/account', 'difficulty' => 'easy', 'category' => 'Fitness', 'is_essential' => false, 'is_verified' => true],

            // ─── Gaming ───
            ['company_name' => 'Xbox Game Pass', 'aliases' => ['XBOX', 'MICROSOFT XBOX', 'XBOX GAME PASS'], 'cancellation_url' => 'https://account.microsoft.com/services/xbox-game-pass', 'difficulty' => 'medium', 'category' => 'Gaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'PlayStation Plus', 'aliases' => ['PLAYSTATION', 'SONY PLAYSTATION', 'PS PLUS', 'PLAYSTATION PLUS'], 'cancellation_url' => 'https://store.playstation.com/subscriptions', 'difficulty' => 'medium', 'category' => 'Gaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Nintendo Online', 'aliases' => ['NINTENDO', 'NINTENDO ONLINE', 'NINTENDO OF AMERICA'], 'cancellation_url' => 'https://accounts.nintendo.com/subscription', 'difficulty' => 'easy', 'category' => 'Gaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'EA Play', 'aliases' => ['EA PLAY', 'ELECTRONIC ARTS', 'EA.COM'], 'cancellation_url' => 'https://myaccount.ea.com/cp-ui/subscription/index', 'difficulty' => 'medium', 'category' => 'Gaming', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Discord Nitro', 'aliases' => ['DISCORD', 'DISCORD TALK', 'DISCORD.COM', 'DISCORD NITRO'], 'cancellation_url' => 'https://discord.com/settings/subscriptions', 'difficulty' => 'easy', 'category' => 'Gaming', 'is_essential' => false, 'is_verified' => true],

            // ─── Shopping ───
            ['company_name' => 'Amazon Prime', 'aliases' => ['AMAZON PRIME', 'AMZN PRIME', 'AMZN DIGITAL PRIME'], 'cancellation_url' => 'https://www.amazon.com/mc/pipelines/cancel', 'difficulty' => 'medium', 'category' => 'Shopping', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Amazon Kids+', 'aliases' => ['AMAZON KIDS', 'AMAZON KIDS+', 'AMZN KIDS'], 'cancellation_url' => 'https://www.amazon.com/gp/help/customer/display.html?nodeId=GQ36XVBQFVMC3TSA', 'difficulty' => 'medium', 'category' => 'Shopping', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Walmart+', 'aliases' => ['WALMART PLUS', 'WALMART+', 'WMT PLUS'], 'cancellation_url' => 'https://www.walmart.com/account/wplus/plan', 'difficulty' => 'easy', 'category' => 'Shopping', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Costco', 'aliases' => ['COSTCO MEMBERSHIP', 'COSTCO WHSE MEMBERSHIP'], 'cancellation_url' => 'https://customerservice.costco.com/app/answers/detail/a_id/1211', 'cancellation_phone' => '(800) 774-2678', 'difficulty' => 'medium', 'category' => 'Shopping', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Instacart+', 'aliases' => ['INSTACART', 'INSTACART.COM', 'INSTACART+'], 'cancellation_url' => 'https://www.instacart.com/store/account/instacart-plus', 'difficulty' => 'easy', 'category' => 'Shopping', 'is_essential' => false, 'is_verified' => true],

            // ─── Hosting & Dev Tools ───
            ['company_name' => 'Contabo', 'aliases' => ['CONTABO', 'CONTABO.COM', 'CONTABO GMBH'], 'cancellation_url' => 'https://my.contabo.com/account/cancel', 'difficulty' => 'medium', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Hivelocity', 'aliases' => ['HIVELOCITY', 'HIVELOCITY.NET'], 'cancellation_url' => 'https://my.hivelocity.net/', 'difficulty' => 'medium', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'DigitalOcean', 'aliases' => ['DIGITALOCEAN', 'DIGITAL OCEAN', 'DIGITALOCEAN.COM'], 'cancellation_url' => 'https://cloud.digitalocean.com/account/billing', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'UptimeRobot', 'aliases' => ['UPTIMEROBOT', 'UPTIMEROBOT.COM'], 'cancellation_url' => 'https://uptimerobot.com/dashboard#myAccount', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Heroku', 'aliases' => ['HEROKU', 'HEROKU.COM', 'SALESFORCE HEROKU'], 'cancellation_url' => 'https://dashboard.heroku.com/account/billing', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Vercel', 'aliases' => ['VERCEL', 'VERCEL.COM', 'VERCEL INC'], 'cancellation_url' => 'https://vercel.com/account/billing', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Nixtla', 'aliases' => ['NIXTLA', 'NIXTLA.IO', 'WWW.NIXTLA.IO'], 'cancellation_url' => 'https://dashboard.nixtla.io/billing', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'VideoBolt', 'aliases' => ['VIDEOBOLT', 'VIDEOBOLT D.O.O', 'VIDEOBOLT.NET'], 'cancellation_url' => 'https://videobolt.net/my-account', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'BunnyWay (BunnyCDN)', 'aliases' => ['BUNNYWAY', 'BUNNYWAY D.O.O', 'BUNNY.NET', 'BUNNYCDN'], 'cancellation_url' => 'https://dash.bunny.net/account/billing', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Cloudflare', 'aliases' => ['CLOUDFLARE', 'CLOUDFLARE.COM', 'CLOUDFLARE INC'], 'cancellation_url' => 'https://dash.cloudflare.com/?account=billing', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'GoDaddy', 'aliases' => ['GODADDY', 'GODADDY.COM', 'GO DADDY'], 'cancellation_url' => 'https://account.godaddy.com/products', 'difficulty' => 'medium', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Namecheap', 'aliases' => ['NAMECHEAP', 'NAMECHEAP.COM'], 'cancellation_url' => 'https://ap.www.namecheap.com/dashboard', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Lodgify', 'aliases' => ['LODGIFY', 'LODGIFY.COM'], 'cancellation_url' => 'https://app.lodgify.com/settings/subscription', 'difficulty' => 'medium', 'category' => 'Software', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Twilio', 'aliases' => ['TWILIO', 'TWILIO.COM', 'TWILIO INC'], 'cancellation_url' => 'https://www.twilio.com/console/billing', 'difficulty' => 'easy', 'category' => 'Software', 'is_essential' => false, 'is_verified' => true],
            ['company_name' => 'Sequencing.com', 'aliases' => ['SEQUENCING', 'SEQUENCING.COM'], 'cancellation_url' => 'https://sequencing.com/settings/subscription', 'difficulty' => 'easy', 'category' => 'Health', 'is_essential' => false, 'is_verified' => false],

            // ─── Phone (essential) ───
            ['company_name' => 'Verizon', 'aliases' => ['VERIZON', 'VERIZON WIRELESS', 'VZW'], 'cancellation_url' => 'https://www.verizon.com/signin/', 'cancellation_phone' => '(800) 922-0204', 'difficulty' => 'hard', 'category' => 'Phone', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'AT&T', 'aliases' => ['AT&T', 'ATT', 'AT&T WIRELESS'], 'cancellation_url' => 'https://www.att.com/my/#/account', 'cancellation_phone' => '(800) 331-0500', 'difficulty' => 'hard', 'category' => 'Phone', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'T-Mobile', 'aliases' => ['T-MOBILE', 'TMOBILE', 'T MOBILE'], 'cancellation_url' => 'https://my.t-mobile.com/account/profile', 'cancellation_phone' => '(800) 937-8997', 'difficulty' => 'hard', 'category' => 'Phone', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Google Fi', 'aliases' => ['GOOGLE FI', 'GOOGLE *FI', 'PROJECT FI'], 'cancellation_url' => 'https://fi.google.com/account#plan', 'difficulty' => 'easy', 'category' => 'Phone', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Mint Mobile', 'aliases' => ['MINT MOBILE', 'MINTMOBILE'], 'cancellation_url' => 'https://my.mintmobile.com/account', 'difficulty' => 'easy', 'category' => 'Phone', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Visible', 'aliases' => ['VISIBLE', 'VISIBLE.COM'], 'cancellation_url' => 'https://www.visible.com/account', 'difficulty' => 'easy', 'category' => 'Phone', 'is_essential' => true, 'is_verified' => true],

            // ─── Internet (essential) ───
            ['company_name' => 'Xfinity/Comcast', 'aliases' => ['XFINITY', 'COMCAST', 'XFINITY.COM'], 'cancellation_url' => 'https://www.xfinity.com/support/cancel-service', 'cancellation_phone' => '(800) 934-6489', 'difficulty' => 'hard', 'category' => 'Internet', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Spectrum', 'aliases' => ['SPECTRUM', 'SPECTRUM.COM', 'CHARTER SPECTRUM'], 'cancellation_url' => 'https://www.spectrum.com/contact-us', 'cancellation_phone' => '(833) 267-6094', 'difficulty' => 'hard', 'category' => 'Internet', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Cox', 'aliases' => ['COX', 'COX COMMUNICATIONS', 'COX.COM'], 'cancellation_url' => 'https://www.cox.com/residential/support.html', 'cancellation_phone' => '(800) 234-3993', 'difficulty' => 'hard', 'category' => 'Internet', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'CenturyLink/Lumen', 'aliases' => ['CENTURYLINK', 'LUMEN', 'CENTURYLINK.COM'], 'cancellation_url' => 'https://www.centurylink.com/home/help/account/cancel.html', 'cancellation_phone' => '(800) 244-1111', 'difficulty' => 'hard', 'category' => 'Internet', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Starlink', 'aliases' => ['STARLINK', 'STARLINK.COM', 'SPACEX STARLINK'], 'cancellation_url' => 'https://www.starlink.com/account', 'difficulty' => 'medium', 'category' => 'Internet', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Relaxed Internet (HughesNet)', 'aliases' => ['RELAXED COMMUNICATIONS', 'RELAXED INTERNET', 'HUGHESNET'], 'cancellation_url' => 'https://www.hughesnet.com/get-help/cancel', 'cancellation_phone' => '(866) 347-3292', 'difficulty' => 'hard', 'category' => 'Internet', 'is_essential' => true, 'is_verified' => false],

            // ─── Insurance (essential) ───
            ['company_name' => 'GEICO', 'aliases' => ['GEICO', 'GEICO.COM'], 'cancellation_url' => 'https://www.geico.com/information/aboutinsurance/cancel-policy/', 'cancellation_phone' => '(800) 861-8380', 'difficulty' => 'medium', 'category' => 'Insurance', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'State Farm', 'aliases' => ['STATE FARM', 'STATEFARM'], 'cancellation_url' => 'https://www.statefarm.com/customer-care/manage-your-accounts', 'cancellation_phone' => '(800) 782-8332', 'difficulty' => 'medium', 'category' => 'Insurance', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Progressive', 'aliases' => ['PROGRESSIVE', 'PROGRESSIVE.COM'], 'cancellation_url' => 'https://www.progressive.com/claims/cancel-policy/', 'cancellation_phone' => '(800) 776-4737', 'difficulty' => 'medium', 'category' => 'Insurance', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Allstate', 'aliases' => ['ALLSTATE', 'ALLSTATE.COM'], 'cancellation_url' => 'https://myaccount.allstate.com/', 'cancellation_phone' => '(800) 255-7828', 'difficulty' => 'medium', 'category' => 'Insurance', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'USAA', 'aliases' => ['USAA', 'USAA.COM'], 'cancellation_url' => 'https://www.usaa.com/', 'cancellation_phone' => '(800) 531-8722', 'difficulty' => 'medium', 'category' => 'Insurance', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Liberty Mutual', 'aliases' => ['LIBERTY MUTUAL', 'LIBERTYMUTUAL'], 'cancellation_url' => 'https://www.libertymutual.com/manage-your-policy', 'cancellation_phone' => '(800) 290-8711', 'difficulty' => 'medium', 'category' => 'Insurance', 'is_essential' => true, 'is_verified' => true],

            // ─── Utilities (essential) ───
            ['company_name' => 'APS Electric', 'aliases' => ['APS', 'ARIZONA PUBLIC SERVICE', 'APS ELECTRIC'], 'cancellation_url' => 'https://www.aps.com/en/Account/My-Account', 'cancellation_phone' => '(602) 371-7171', 'difficulty' => 'hard', 'category' => 'Utilities', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'SRP Power', 'aliases' => ['SRP', 'SALT RIVER PROJECT', 'SRP POWER'], 'cancellation_url' => 'https://srpnet.com/account/', 'cancellation_phone' => '(602) 236-8888', 'difficulty' => 'hard', 'category' => 'Utilities', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Republic Services', 'aliases' => ['REPUBLIC SVCS', 'REPUBLIC SERVICES'], 'cancellation_url' => 'https://www.republicservices.com/customer-support', 'cancellation_phone' => '(800) 433-1646', 'difficulty' => 'medium', 'category' => 'Utilities', 'is_essential' => true, 'is_verified' => true],
            ['company_name' => 'Waste Management', 'aliases' => ['WASTE MGMT', 'WASTE MANAGEMENT', 'WM'], 'cancellation_url' => 'https://www.wm.com/us/en/my-wm/my-dashboard', 'cancellation_phone' => '(866) 797-9018', 'difficulty' => 'medium', 'category' => 'Utilities', 'is_essential' => true, 'is_verified' => true],

            // ─── Additional Services from User's Data ───
            ['company_name' => 'Squid Proxies', 'aliases' => ['SQUID PROXIES', 'SQUIDIPHOST', 'SQUID VENTURES'], 'cancellation_url' => 'https://www.squidproxies.com/members/clientarea.php', 'difficulty' => 'easy', 'category' => 'Hosting', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Diamond Dance Works', 'aliases' => ['DIAMOND DANCE', 'DIAMOND DANCE WORKS'], 'cancellation_url' => null, 'cancellation_phone' => null, 'difficulty' => 'medium', 'category' => 'Recreation', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Stasis Labs', 'aliases' => ['STASIS', 'STASIS LABS'], 'cancellation_url' => null, 'difficulty' => 'easy', 'category' => 'Health', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Taylor Morrison', 'aliases' => ['TAYLOR', 'TAYLOR MORRISON'], 'cancellation_url' => null, 'difficulty' => 'medium', 'category' => 'Housing', 'is_essential' => false, 'is_verified' => false],
            ['company_name' => 'Tesla Subscription', 'aliases' => ['TESLA'], 'cancellation_url' => 'https://www.tesla.com/teslaaccount', 'difficulty' => 'easy', 'category' => 'Auto', 'is_essential' => false, 'is_verified' => false],
        ];

        foreach ($providers as $provider) {
            CancellationProvider::updateOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($provider['company_name'])],
                array_merge($provider, ['slug' => \Illuminate\Support\Str::slug($provider['company_name'])])
            );
        }
    }
}
