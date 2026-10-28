<?php

declare(strict_types=1);

namespace Lemonade\Profiler\Tests;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Lemonade\Framework\Observability\Http\HttpMetrics;
use Lemonade\Profiler\Capture\ProfileContext;
use Lemonade\Profiler\Capture\SqlSanitizer;
use Lemonade\Profiler\Presentation\ProfilerLocale;
use Lemonade\Profiler\Presentation\ProfilerTimeline;
use Lemonade\Profiler\Presentation\ProfilerTranslator;
use Lemonade\Profiler\Presentation\ProfilerUi;
use Lemonade\Profiler\Profile;
use Lemonade\Profiler\ProfilerConfig;
use Lemonade\Profiler\Tests\Support\TestApplication;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalizationTest extends TestCase
{
    public function testDictionariesHaveMatchingKeysAndFallbackAndParametersWork(): void
    {
        $dictionaries = ProfilerTranslator::dictionaries();
        self::assertSame(array_keys($dictionaries[ProfilerLocale::En->value]), array_keys($dictionaries[ProfilerLocale::Cs->value]));
        self::assertSame(['cs', 'en'], array_keys($dictionaries));
        foreach ($dictionaries as $dictionary) {
            self::assertNotContains('', $dictionary);
        }
        self::assertSame(ProfilerLocale::Cs, ProfilerTranslator::choose('cs', 'en-US'));
        self::assertSame(ProfilerLocale::En, ProfilerTranslator::choose('en', 'cs-CZ'));
        self::assertSame(ProfilerLocale::Cs, ProfilerTranslator::choose(null, 'en;q=0.4,cs-CZ;q=0.9'));
        self::assertSame(ProfilerLocale::En, ProfilerTranslator::choose('fr', 'de-DE'));
        self::assertSame('Uloženo: 7', (new ProfilerTranslator(ProfilerLocale::Cs))->translate('common.retained', ['count' => 7]));
        self::assertSame('Unavailable', (new ProfilerTranslator(ProfilerTranslator::choose('fr')))->translate('common.unavailable'));
        $czech = new ProfilerTranslator(ProfilerLocale::Cs);
        self::assertSame('1 předchozí požadavek', $czech->previousRequestCount(1));
        self::assertSame('2 předchozí požadavky', $czech->previousRequestCount(2));
        self::assertSame('5 předchozích požadavků', $czech->previousRequestCount(5));
        self::assertSame('21 předchozích požadavků', $czech->previousRequestCount(21));
        self::assertSame('1 previous request', (new ProfilerTranslator(ProfilerLocale::En))->previousRequestCount(1));
    }

    #[DataProvider('languageChoices')]
    public function testLanguageChoiceHonorsQualityAndNormalizesSupportedTags(
        ?string $explicit,
        string $header,
        ProfilerLocale $expected,
    ): void {
        self::assertSame($expected, ProfilerTranslator::choose($explicit, $header));
    }

    /**
     * @return iterable<string, array{?string, string, ProfilerLocale}>
     */
    public static function languageChoices(): iterable
    {
        yield 'explicit normalized choice wins' => [' CS-cz ', 'en;q=1', ProfilerLocale::Cs];
        yield 'unsupported explicit choice uses header' => ['fr', 'cs-CZ', ProfilerLocale::Cs];
        yield 'higher quality wins' => [null, 'cs;q=0.4,en;q=0.9', ProfilerLocale::En];
        yield 'higher quality wins in reverse order' => [null, 'en;q=0.4,cs;q=0.9', ProfilerLocale::Cs];
        yield 'equal quality keeps first Czech' => [null, 'cs;q=0.8,en;q=0.8', ProfilerLocale::Cs];
        yield 'equal quality keeps first English' => [null, 'en;q=0.8,cs;q=0.8', ProfilerLocale::En];
        yield 'default quality is one' => [null, 'en;q=0.9,CS-CZ', ProfilerLocale::Cs];
        yield 'zero quality is excluded' => [null, 'cs;q=0,en;q=0.1', ProfilerLocale::En];
        yield 'unsupported language is skipped' => [null, 'de;q=1,cs;q=0.5,en;q=0.4', ProfilerLocale::Cs];
        yield 'whitespace and case' => [null, ' EN-us ; Q=0.5 , CS-cz ; q=0.9 ', ProfilerLocale::Cs];
        yield 'invalid quality is skipped' => [null, 'cs;q=1.1,en;q=0.2', ProfilerLocale::En];
        yield 'excess precision is skipped' => [null, 'cs;q=0.9999,en;q=0.2', ProfilerLocale::En];
        yield 'empty fractional part' => [null, 'cs;q=1.,en;q=0.5', ProfilerLocale::Cs];
        yield 'no supported language' => [null, 'de-DE,fr;q=0.8', ProfilerLocale::En];
        yield 'no positive supported language' => [null, 'cs;q=0,en;q=0', ProfilerLocale::En];
        yield 'wildcard uses fallback' => [null, '*', ProfilerLocale::En];
        yield 'empty header' => [null, '', ProfilerLocale::En];
        yield 'header remains bounded' => [null, str_repeat(' ', 512) . 'cs', ProfilerLocale::En];
    }

    public function testInstanceExposesNormalizedLocaleAndPreservesParameterBehavior(): void
    {
        self::assertSame(ProfilerLocale::En, (new ProfilerTranslator())->locale());
        self::assertSame(ProfilerLocale::En, (new ProfilerTranslator(ProfilerTranslator::choose('../cs')))->locale());
        self::assertSame(ProfilerLocale::En, (new ProfilerTranslator(ProfilerTranslator::choose('fr')))->locale());
        self::assertSame(ProfilerLocale::En, (new ProfilerTranslator(ProfilerTranslator::choose('EN-us')))->locale());
        $translator = new ProfilerTranslator(ProfilerTranslator::choose(' CS-cz '));
        self::assertSame(ProfilerLocale::Cs, $translator->locale());
        self::assertSame('Uloženo: 7', $translator->translate('common.retained', ['count' => 7]));
        self::assertSame('Uloženo: {count}', $translator->translate('common.retained'));
        self::assertSame('missing 1.5 {untouched}', $translator->translate('missing {value} {untouched}', ['value' => 1.5]));
        self::assertSame('{second}', $translator->translate('{first}', ['first' => '{second}', 'second' => 'replacement']));
    }

    public function testHistoryAndDetailPreserveSelectedLanguageWithoutChangingProfile(): void
    {
        $app = new TestApplication();
        try {
            $page = $app->kernel->run(new ServerRequest('GET', '/page/1'));
            $profilePath = $page->getHeaderLine('X-Lemonade-Profiler');
            $history = $app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler?lang=cs', ['Accept-Language' => 'en-US']));
            $historyDom = self::dom((string) $history->getBody());
            self::assertSame('cs', self::language($historyDom));
            self::assertGreaterThanOrEqual(1, self::linkCount($historyDom, $profilePath . '?lang=cs'));
            $detail = $app->kernel->run(new ServerRequest('GET', $profilePath . '?lang=cs'));
            $detailDom = self::dom((string) $detail->getBody());
            self::assertSame('cs', self::language($detailDom));
            self::assertSame(1, self::linkCount($detailDom, $profilePath . '?lang=en'));
            $english = $app->kernel->run(new ServerRequest('GET', $profilePath . '?lang=en', ['Accept-Language' => 'cs-CZ']));
            self::assertSame('en', self::language(self::dom((string) $english->getBody())));
            $automatic = $app->kernel->run(new ServerRequest('GET', '/_lemonade/profiler?lang=xx', ['Accept-Language' => 'cs-CZ']));
            self::assertSame('cs', self::language(self::dom((string) $automatic->getBody())));
        } finally {
            $app->close();
        }
    }

    public function testTranslatedTemplateStillEscapesDynamicRouteText(): void
    {
        $app = new TestApplication();
        try {
            $app->kernel->run(new ServerRequest('GET', '/page/1'));
            $profile = $app->container->get(\Lemonade\Profiler\Storage\ProfileStore::class)->history()[0];
            $data = $profile->toArray();
            $data['route'] = '<img src=x onerror=alert(1)>';
            $data['renderings'][] = ['order' => 99, 'template' => '<svg onload=alert(1)>', 'start_ms' => 0.0, 'elapsed_ms' => 0.0, 'completed' => true];
            $dom = self::dom((new ProfilerUi(new ProfilerTimeline()))->profile(new Profile($data), ProfilerLocale::Cs));
            $executables = (new DOMXPath($dom))->query('//*[@onload or @onerror]');
            self::assertInstanceOf(DOMNodeList::class, $executables);
            self::assertSame(0, $executables->length);
            self::assertStringContainsString($data['route'], $dom->textContent);
        } finally {
            $app->close();
        }
    }

    public function testMetadataLabelsAndStateValuesAreTranslatedOnlyForPresentation(): void
    {
        $context = new ProfileContext(new ProfilerConfig(), new SqlSanitizer(), hrtime(true));
        $context->captureSession();
        $context->captureRuntime('dev', 'nginx/1.26.3 private-host');
        $context->completed(new HttpMetrics(1.0, 1024, 0, 0.0));
        $data = $context->snapshot('GET', 200, null)->toArray();
        self::assertArrayHasKey('session', $data['diagnostics']);
        self::assertArrayHasKey('composer', $data['diagnostics']);
        $data['diagnostics']['session']['values'] = array_replace(
            $data['diagnostics']['session']['values'],
            ['status' => 'inactive', 'secure' => 'auto'],
        );
        $data['diagnostics']['composer']['values'] = array_replace(
            $data['diagnostics']['composer']['values'],
            ['status' => 'available_limited', 'application' => 'active'],
        );
        $profile = Profile::decode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        self::assertNotNull($profile);
        self::assertSame($data, $profile->toArray());
        $ui = new ProfilerUi(new ProfilerTimeline());
        foreach (ProfilerLocale::cases() as $locale) {
            $translator = new ProfilerTranslator($locale);
            $dom = self::dom($ui->profile($profile, $locale));
            $xpath = new DOMXPath($dom);
            foreach ($data['diagnostics'] as $name => $section) {
                $labels = [];
                foreach (array_keys($section['values']) as $key) {
                    $translation = $translator->translate('diagnostics.' . $key);
                    self::assertNotSame('diagnostics.' . $key, $translation);
                    $labels[] = $translation;
                }
                $nodes = $xpath->query('//*[@id="' . $name . '"]/dl/dt');
                self::assertInstanceOf(DOMNodeList::class, $nodes);
                $renderedLabels = [];
                foreach ($nodes as $node) {
                    self::assertInstanceOf(DOMElement::class, $node);
                    $renderedLabels[] = $node->textContent;
                }
                self::assertSame($labels, $renderedLabels);
            }
            foreach (['inactive', 'auto', 'available_limited', 'unavailable'] as $value) {
                self::assertStringContainsString($translator->translate('common.' . $value), $dom->textContent);
            }
            self::assertStringContainsString('active', $xpath->evaluate('string(//*[@id="composer"]/dl/dd[2])'));
            self::assertSame($data, $profile->toArray());
        }
        $toolbar = $ui->toolbarData($profile);
        self::assertSame(PHP_VERSION, $toolbar['server']['php_version']);
        self::assertSame('nginx/1.26.3', $toolbar['server']['web_server']);
        self::assertStringNotContainsString('private-host', json_encode($toolbar, JSON_THROW_ON_ERROR));
    }

    public function testQueryCountsPreserveCzechAndEnglishForms(): void
    {
        $czech = new ProfilerTranslator(ProfilerLocale::Cs);
        $english = new ProfilerTranslator(ProfilerLocale::En);
        foreach ([0 => '0 dotazů', 1 => '1 dotaz', 2 => '2 dotazy', 4 => '4 dotazy', 5 => '5 dotazů',
            11 => '11 dotazů', 12 => '12 dotazů', 14 => '14 dotazů', 21 => '21 dotaz', 22 => '22 dotazy', 111 => '111 dotazů'] as $count => $expected) {
            self::assertSame($expected, $czech->queryCount($count));
            self::assertSame($count === 1 ? '1 query' : $count . ' queries', $english->queryCount($count));
        }
    }

    public function testRenderingReturnsContentWithoutLeakingIntoTheCallersBuffer(): void
    {
        $ui = new ProfilerUi(new ProfilerTimeline());
        ob_start();
        try {
            echo 'caller output';
            $level = ob_get_level();
            self::assertNotSame('', $ui->history([], ProfilerLocale::Cs));
            self::assertSame($level, ob_get_level());
            self::assertSame('caller output', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    private static function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $dom;
    }

    private static function language(DOMDocument $dom): string
    {
        self::assertInstanceOf(DOMElement::class, $dom->documentElement);
        return $dom->documentElement->getAttribute('lang');
    }

    private static function linkCount(DOMDocument $dom, string $url): int
    {
        $links = (new DOMXPath($dom))->query('//a[@href="' . $url . '"]');
        self::assertInstanceOf(DOMNodeList::class, $links);
        return $links->length;
    }
}
