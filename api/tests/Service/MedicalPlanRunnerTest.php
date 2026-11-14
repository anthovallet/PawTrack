<?php

namespace App\Tests\Service;

use App\Entity\Animal;
use App\Entity\MedicalPlan;
use App\Entity\User;
use App\Repository\MedicalEventRepository;
use App\Repository\MedicalPlanRepository;
use App\Service\MedicalPlanRunner;
use App\Service\ReminderScheduler;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class MedicalPlanRunnerTest extends TestCase
{
    private MedicalPlanRunner $runner;

    // Gardé à part : certains tests lui dictent sa réponse avant d'appeler le runner.
    private MedicalEventRepository&Stub $medicalEventRepository;

    protected function setUp(): void
    {
        $this->medicalEventRepository = $this->createStub(MedicalEventRepository::class);

        $this->runner = new MedicalPlanRunner(
            $this->createStub(MedicalPlanRepository::class),
            $this->medicalEventRepository,
            // Le vrai : on a besoin des rappels qu'il accroche à l'échéance.
            new ReminderScheduler(
                $this->createStub(MedicalEventRepository::class),
                $this->createStub(EntityManagerInterface::class),
            ),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    /** Un plan jamais exécuté engendre sa première échéance à la date choisie. */
    public function testFirstOccurrenceFallsOnStartsAt(): void
    {
        $plan = $this->makePlan('2026-08-21');

        $event = $this->runner->createNextOccurrence($plan);

        $this->assertSame('2026-08-21', $event->getDate()->format('Y-m-d'));
    }

    /** La prochaine échéance part de la dernière réalisation, décalée de la fréquence. */
    public function testOccurrenceEveryThreeMonths(): void
    {
        $plan = $this->makePlan('2026-08-01')
            ->setLastExecutedAt(new \DateTimeImmutable('2026-11-01'))
            ->setFrequencyValue(3)
            ->setFrequency('month');

        $event = $this->runner->createNextOccurrence($plan);

        $this->assertSame('2027-02-01', $event->getDate()->format('Y-m-d'));
    }

    /** Une unité de fréquence hors liste blanche est refusée au lieu de produire une date absurde. */
    public function testUnknownFrequencyUnitThrows(): void
    {
        $plan = $this->makePlan('2026-08-01')
            ->setLastExecutedAt(new \DateTimeImmutable('2026-11-01'))
            ->setFrequencyValue(2)
            ->setFrequency('fortnight');

        // Annoncée AVANT l'Act : une fois l'exception levée, plus aucune ligne du test ne s'exécute.
        $this->expectException(\LogicException::class);

        $this->runner->createNextOccurrence($plan);
    }

    /** Un plan qui a déjà son échéance ouverte n'en reçoit pas une seconde. */
    public function testSyncPlanDoesNothingWhenOccurrenceIsOpen(): void
    {
        $this->medicalEventRepository->method('hasOpenOccurrence')->willReturn(true);

        $event = $this->runner->syncPlan($this->makePlan('2026-08-01'));

        $this->assertNull($event);
    }

    /** L'échéance engendrée par un plan naît avec ses rappels, sans passer par l'API. */
    public function testGeneratedOccurrenceHasItsReminders(): void
    {
        $event = $this->runner->createNextOccurrence($this->makePlan('2026-08-21'));

        $this->assertCount(2, $event->getReminders());
    }

    private function makePlan(string $startsAt): MedicalPlan
    {
        return (new MedicalPlan())
            ->setName('Vermifuge')
            ->setAnimal((new Animal())->setOwner(new User()))
            ->setStartsAt(new \DateTimeImmutable($startsAt));
    }
}
