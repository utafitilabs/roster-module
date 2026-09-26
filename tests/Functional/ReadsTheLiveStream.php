<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Cookie;
use Uhifadhi\Bundle\AreaBundle\Service\PresencePublisher;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Roster\Tests\Integration\TestKernel;

/**
 * WHAT A LIVE PAGE HANDS THE BROWSER: the hub's subscriber cookie, and the
 * plate's stream. The topics are read out of the cookie's own JWT, because a
 * cookie for the wrong topic is a cookie the hub refuses. Which suffix a
 * topic carries (the control room's, a rank's) is the area's business; this
 * only asks that the cookie and the plate agree and are the area's own.
 */
trait ReadsTheLiveStream
{
    /** @return list<string> */
    private static function subscribedTopics(KernelBrowser $browser): array
    {
        $cookie = null;
        foreach ($browser->getResponse()->headers->getCookies() as $one) {
            if ('mercureAuthorization' === $one->getName()) {
                $cookie = $one;
            }
        }
        self::assertInstanceOf(Cookie::class, $cookie, 'the subscriber cookie is on the response');

        $parts = explode('.', (string) $cookie->getValue());
        self::assertCount(3, $parts, 'a JWT');
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsArray($claims['mercure'] ?? null);
        self::assertIsArray($claims['mercure']['subscribe'] ?? null);

        return array_values(array_map(static fn (mixed $t): string => \is_string($t) ? $t : '', $claims['mercure']['subscribe']));
    }

    /** @return array{hub: mixed, topics: list<string>} */
    private static function plateStream(KernelBrowser $browser): array
    {
        $crawler = new Crawler((string) $browser->getResponse()->getContent());
        $extra = $crawler->filter('[data-symfony--ux-leaflet-map--map-extra-value]')->first()->attr('data-symfony--ux-leaflet-map--map-extra-value');
        $decoded = json_decode((string) $extra, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[AtlasMap::EXTRA_KEY] ?? null);
        $live = $decoded[AtlasMap::EXTRA_KEY]['live'] ?? null;
        self::assertIsArray($live, 'the plate carries a stream');
        self::assertIsArray($live['topics'] ?? null);

        return ['hub' => $live['hub'] ?? null, 'topics' => array_values(array_map(static fn (mixed $t): string => \is_string($t) ? $t : '', $live['topics']))];
    }

    /** @param list<string> $areaUuids */
    private static function assertStreamsTheAreas(KernelBrowser $browser, array $areaUuids): void
    {
        $topics = self::subscribedTopics($browser);
        $stream = self::plateStream($browser);

        self::assertSame(TestKernel::HUB_URL, $stream['hub']);
        self::assertSame($topics, $stream['topics'], 'the plate follows exactly what the cookie lets it');
        self::assertCount(\count($areaUuids), $topics, 'one topic per area');
        foreach ($areaUuids as $i => $uuid) {
            $prefix = PresencePublisher::topicFor($uuid);
            self::assertTrue(str_starts_with($topics[$i], $prefix), \sprintf('"%s" is a topic of the area "%s"', $topics[$i], $uuid));
        }
    }
}
