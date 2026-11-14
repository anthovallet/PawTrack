<?php

namespace App\Tests\Service;

use App\Entity\MedicalEvent;
use App\Entity\Reminder;
use App\Entity\User;
use App\Repository\ReminderRepository;
use App\Service\ReminderSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

class ReminderSenderTest extends TestCase
{
    /** Deux rappels le même matin pour le même utilisateur : un seul mail, sujet au pluriel. */
    public function testRemindersOfSameUserShareOneDigest(): void
    {
        $user = $this->makeUser(1, 'alice@example.com');

        // L'attente se déclare AVANT l'Act : le mock doit savoir quoi surveiller.
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(
                fn (TemplatedEmail $email): bool => '2 soins arrivent pour vos animaux' === $email->getSubject()
            ));

        $sender = $this->makeSender([
            $this->makeReminder($user, '2026-09-20'),
            $this->makeReminder($user, '2026-09-22'),
        ], $mailer);

        $report = $sender->sendDue(new \DateTimeImmutable('2026-09-13 08:00'));

        $this->assertSame(['users' => 1, 'reminders' => 2, 'failures' => 0], $report);
    }

    /** Un rappel seul : sujet au singulier. */
    public function testSingleReminderGetsSingularSubject(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(
                fn (TemplatedEmail $email): bool => 'Un soin arrive pour votre animal' === $email->getSubject()
            ));

        $sender = $this->makeSender([
            $this->makeReminder($this->makeUser(1, 'alice@example.com'), '2026-09-20'),
        ], $mailer);

        $sender->sendDue(new \DateTimeImmutable('2026-09-13 08:00'));
    }

    /** Un destinataire injoignable ne prive pas les autres de leur mail, et son rappel repartira. */
    public function testTransportFailureDoesNotBlockOtherUsers(): void
    {
        $now = new \DateTimeImmutable('2026-09-13 08:00');
        $forAlice = $this->makeReminder($this->makeUser(1, 'alice@example.com'), '2026-09-20');
        $forBob = $this->makeReminder($this->makeUser(2, 'bob@example.com'), '2026-09-20');

        // Un stub, pas un mock : on ne vérifie rien sur lui, il doit juste échouer pour Alice.
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (TemplatedEmail $email): void {
            if ('alice@example.com' === $email->getTo()[0]->getAddress()) {
                throw new TransportException('Boîte injoignable');
            }
        });

        $report = $this->makeSender([$forAlice, $forBob], $mailer)->sendDue($now);

        $this->assertSame(['users' => 1, 'reminders' => 1, 'failures' => 1], $report);
        // C'est ce null qui fera repartir le rappel d'Alice au prochain passage.
        $this->assertNull($forAlice->getSentAt());
        $this->assertSame($now, $forBob->getSentAt());
    }

    /** Le repository est interrogé avec le plancher : rien de plus vieux que 2 jours. */
    public function testFindDueIsBoundedByGracePeriod(): void
    {
        $repository = $this->createMock(ReminderRepository::class);
        $repository->expects($this->once())
            ->method('findDue')
            // with() compare par valeur (comme assertEquals), pas par instance.
            ->with(
                new \DateTimeImmutable('2026-09-13 08:00'),
                new \DateTimeImmutable('2026-09-11 08:00'),
            )
            ->willReturn([]);

        $sender = new ReminderSender(
            $repository,
            $this->createStub(MailerInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $sender->sendDue(new \DateTimeImmutable('2026-09-13 08:00'));
    }

    /** Le délai annoncé compte des jours calendaires, pas des heures écoulées. */
    public function testDaysUntilCountsCalendarDays(): void
    {
        $user = $this->makeUser(1, 'alice@example.com');
        $sent = [];

        // Stub qui garde les mails reçus pour qu'on les inspecte dans l'Assert.
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (TemplatedEmail $email) use (&$sent): void {
            $sent[] = $email;
        });

        $this->makeSender([
            $this->makeReminder($user, '2026-09-28'),   // le jour même
            $this->makeReminder($user, '2026-09-29'),   // demain, une heure plus tard
        ], $mailer)->sendDue(new \DateTimeImmutable('2026-09-28 23:00'));

        $this->assertCount(1, $sent);
        $lines = $sent[0]->getContext()['lines'];
        $this->assertSame(0, $lines[0]['daysUntil']);
        // Non-régression : une différence en secondes répondait 0 ici, donc « aujourd'hui ».
        $this->assertSame(1, $lines[1]['daysUntil']);
    }

    private function makeSender(array $dueReminders, MailerInterface $mailer): ReminderSender
    {
        $repository = $this->createStub(ReminderRepository::class);
        $repository->method('findDue')->willReturn($dueReminders);

        return new ReminderSender($repository, $mailer, $this->createStub(EntityManagerInterface::class));
    }

    /**
     * Pose l'id par réflexion : deux User non persistés auraient tous deux un id
     * null, et groupByUser() les fusionnerait en un seul destinataire.
     */
    private function makeUser(int $id, string $email): User
    {
        $user = (new User())->setEmail($email);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function makeReminder(User $user, string $eventDate): Reminder
    {
        return (new Reminder())
            ->setUser($user)
            ->setMedicalEvent((new MedicalEvent())->setDate(new \DateTime($eventDate)));
    }
}
