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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\EditedDay;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * A SHEET WITH ENOUGH ON IT TO DRIVE IN A BROWSER, written to disk.
 *
 * NOT AN ASSERTION ABOUT THE PRODUCT — a fixture generator. The rendered
 * markup goes to a harness the headless browser opens, because the checks
 * that matter here are the ones no HTTP test can make: whether a click
 * opens the menu, where the panel lands, and what `elementFromPoint`
 * returns at its centre.
 */
final class SheetBrowserDumpTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        self::freshDatabase($this->em);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);
        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));

        $monday = new \DateTimeImmutable('today')->modify('monday this week');
        $stations = [];
        $people = [];

        foreach ([['eastgate post', 'ST-01'], ['fig tree ranger post', 'ST-02'], ['lakeshore post', 'ST-03']] as $seat => [$name, $code]) {
            $station = new Station()->setArea($this->area)->setName($name)->setCode($code)
                ->setPoint(\sprintf('{"type":"Point","coordinates":[12.%d,-5.7]}', 3 + $seat));
            $this->em->persist($station);
            $stations[] = $station;
        }
        $this->em->flush();

        foreach ($stations as $index => $station) {
            for ($seat = 0; $seat < 12; ++$seat) {
                $person = new User()->setPassword('x')
                    ->setEmail(\sprintf('r%d-%d@example.test', $index, $seat))
                    ->setFirstName(\sprintf('R%d%d', $index, $seat))->setLastName('Example');
                $this->em->persist($person);
                $people[] = [$person, $station, $seat];
            }
        }
        $this->em->flush();

        foreach ($people as [$person, $station, $seat]) {
            $this->em->persist(
                new Posting()->setStation($station)->setPerson($person)
                    ->setSince(new \DateTimeImmutable('-1 year'))->setSource(PostingSource::WrittenHere),
            );

            for ($offset = 0; $offset < 28; ++$offset) {
                if (0 === ($offset + $seat) % 5) {
                    continue;
                }

                $this->em->persist(new Duty(
                    $this->area,
                    $station,
                    $person,
                    0 === ($offset + $seat) % 2 ? 'day' : 'night',
                    $monday->modify(\sprintf('+%d days', $offset)),
                ));
            }

            // A hand mark on one day of each row, so the corner mark and the
            // menu's mark-clearing verb are both on the page.
            $this->em->persist(new EditedDay($station, $monday->modify('+2 days'), null, new \DateTimeImmutable(), $person, false));
        }

        $this->em->flush();
        $this->everyAreaRunsTheRoster($this->em);
        $this->em->flush();
    }

    public function testTheSheetIsWrittenWhereTheBrowserCanOpenIt(): void
    {
        $out = getenv('SHEET_DUMP_DIR');
        if (!\is_string($out) || '' === $out) {
            self::markTestSkipped('Set SHEET_DUMP_DIR to write the harness fixture.');
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        foreach ([2, 4] as $weeks) {
            $this->client->request('GET', \sprintf('/areas/%s/modules/roster/week?weeks=%d', $this->area->getUuidString(), $weeks));
            self::assertResponseIsSuccessful();

            $html = (string) $this->client->getResponse()->getContent();
            file_put_contents(\sprintf('%s/week-%dw.html', rtrim($out, '/'), $weeks), $html);
        }

        self::assertFileExists($out.'/week-2w.html');
    }
}
