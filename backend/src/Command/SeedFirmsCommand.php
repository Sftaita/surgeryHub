<?php

namespace App\Command;

use App\Entity\Firm;
use App\Entity\MaterialItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-firms',
    description: 'Seed firms and their implant catalogue.'
)]
class SeedFirmsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SurgicalHub — Seed Firms');

        // Remove existing firms and material items
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE material_item');
        $conn->executeStatement('TRUNCATE TABLE firm');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        $io->comment('Firmes et catalogue purgés.');

        $firms = [
            [
                'name'           => 'Zimmer Biomet',
                'active'         => true,
                'country'        => 'Belgique',
                'representative' => 'Laurent Renard',
                'phone'          => '+32 2 555 01 00',
                'billingEmail'   => 'facturation.be@zimmerbiomet.com',
                'billingEmailCc' => ['comptabilite@zimmerbiomet.com'],
                'implants'       => [
                    [
                        'referenceCode' => 'ZB-TKA-001',
                        'label'         => 'Prothèse totale de genou NexGen',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse totale de genou',
                        'material'      => 'Cobalt-Chrome / Polyéthylène',
                        'description'   => 'Système de prothèse totale de genou à haute flexion, compatible ciment et sans ciment.',
                    ],
                    [
                        'referenceCode' => 'ZB-THA-002',
                        'label'         => 'Prothèse totale de hanche Taperloc',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse totale de hanche',
                        'material'      => 'Titane / Céramique',
                        'description'   => 'Tige fémorale non cimentée à fixation biologique. Disponible en standard et microplasty.',
                    ],
                    [
                        'referenceCode' => 'ZB-RSA-003',
                        'label'         => 'Prothèse d\'épaule inversée Comprehensive',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse d\'épaule inversée',
                        'material'      => 'Titane / Polyéthylène',
                        'description'   => 'Système d\'épaule inversée modulaire indiqué pour les ruptures massives de coiffe.',
                    ],
                    [
                        'referenceCode' => 'ZB-SPINE-004',
                        'label'         => 'Cage intersomatique lombaire Timberline',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Cage rachidienne',
                        'material'      => 'PEEK / Titane',
                        'description'   => 'Cage TLIF à expansion latérale pour fusion lombaire minimalement invasive.',
                    ],
                    [
                        'referenceCode' => 'ZB-INST-010',
                        'label'         => 'Kit instrumentation NexGen',
                        'unit'          => 'kit',
                        'isImplant'     => false,
                        'implantType'   => null,
                        'material'      => 'Acier inoxydable',
                        'description'   => 'Set complet d\'instruments chirurgicaux pour pose de prothèse NexGen.',
                    ],
                ],
            ],
            [
                'name'           => 'Stryker',
                'active'         => true,
                'country'        => 'Belgique',
                'representative' => 'Marie Dubois',
                'phone'          => '+32 2 555 02 00',
                'billingEmail'   => 'belgium.billing@stryker.com',
                'billingEmailCc' => ['ar.belgium@stryker.com'],
                'implants'       => [
                    [
                        'referenceCode' => 'STR-TKA-001',
                        'label'         => 'Prothèse totale de genou Triathlon',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse totale de genou',
                        'material'      => 'Cobalt-Chrome / Polyéthylène',
                        'description'   => 'Système polyvalent avec option de conservation ou sacrifice du LCP. Cinématique naturelle.',
                    ],
                    [
                        'referenceCode' => 'STR-THA-002',
                        'label'         => 'Prothèse totale de hanche Accolade II',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse totale de hanche',
                        'material'      => 'Titane / Céramique / Polyéthylène',
                        'description'   => 'Tige à section variable en titane forgé. Fixation biologique proximale.',
                    ],
                    [
                        'referenceCode' => 'STR-RSA-003',
                        'label'         => 'Prothèse d\'épaule inversée ReUnion',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse d\'épaule inversée',
                        'material'      => 'Titane / Polyéthylène',
                        'description'   => 'Conception sans col avec inserts polyéthylène à différentes contraintes.',
                    ],
                    [
                        'referenceCode' => 'STR-NAIL-004',
                        'label'         => 'Clou centromédullaire Gamma3',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Clou centromédullaire',
                        'material'      => 'Titane',
                        'description'   => 'Clou trochantérien pour fractures per- et sous-trochantériennes du fémur proximal.',
                    ],
                    [
                        'referenceCode' => 'STR-SPINE-005',
                        'label'         => 'Vis pédiculaire Solera',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Vis pédiculaire',
                        'material'      => 'Titane',
                        'description'   => 'Vis polyaxiale à blocage multi-axial pour fixation rachidienne postérieure.',
                    ],
                    [
                        'referenceCode' => 'STR-INST-010',
                        'label'         => 'Moteur chirurgical System 7',
                        'unit'          => 'unité',
                        'isImplant'     => false,
                        'implantType'   => null,
                        'material'      => 'Acier / Aluminium',
                        'description'   => 'Système motorisé haute performance pour ostéotomie et alésage. Batterie Li-ion.',
                    ],
                ],
            ],
            [
                'name'           => 'DePuy Synthes',
                'active'         => true,
                'country'        => 'Belgique',
                'representative' => 'Nicolas Martin',
                'phone'          => '+32 2 555 03 00',
                'billingEmail'   => 'depuy.billing.be@jnj.com',
                'billingEmailCc' => [],
                'implants'       => [
                    [
                        'referenceCode' => 'DPS-TKA-001',
                        'label'         => 'Prothèse totale de genou ATTUNE',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse totale de genou',
                        'material'      => 'Cobalt-Chrome / Polyéthylène',
                        'description'   => 'Système ATTUNE avec cinématique à rayon progressif. Fixation cimentée ou non cimentée.',
                    ],
                    [
                        'referenceCode' => 'DPS-THA-002',
                        'label'         => 'Prothèse totale de hanche Corail',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Prothèse totale de hanche',
                        'material'      => 'Titane / Céramique / Polyéthylène',
                        'description'   => 'Tige Corail hydroxyapatite, référence mondiale pour fixation sans ciment.',
                    ],
                    [
                        'referenceCode' => 'DPS-PLATE-003',
                        'label'         => 'Plaque DCP 4.5mm',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Plaque d\'ostéosynthèse',
                        'material'      => 'Acier inoxydable',
                        'description'   => 'Plaque à compression dynamique pour ostéosynthèse des os longs.',
                    ],
                    [
                        'referenceCode' => 'DPS-SPINE-004',
                        'label'         => 'Cage cervicale UNISPACE',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Cage rachidienne cervicale',
                        'material'      => 'PEEK',
                        'description'   => 'Cage cervicale ACIF avec plaque intégrée et dents d\'ancrage.',
                    ],
                    [
                        'referenceCode' => 'DPS-SCREW-005',
                        'label'         => 'Vis corticale 3.5mm',
                        'unit'          => 'pièce',
                        'isImplant'     => true,
                        'implantType'   => 'Vis d\'ostéosynthèse',
                        'material'      => 'Acier inoxydable',
                        'description'   => 'Vis corticale standard pour fixation dans os cortical. Compatible plaques DCP/LC-DCP.',
                    ],
                ],
            ],
        ];

        $firmCount   = 0;
        $implantCount = 0;

        foreach ($firms as $firmData) {
            $firm = new Firm();
            $firm->setName($firmData['name']);
            $firm->setActive($firmData['active']);
            $firm->setCountry($firmData['country']);
            $firm->setRepresentative($firmData['representative']);
            $firm->setPhone($firmData['phone']);
            $firm->setBillingEmail($firmData['billingEmail']);
            $firm->setBillingEmailCc($firmData['billingEmailCc'] ?: null);
            $this->em->persist($firm);

            foreach ($firmData['implants'] as $implantData) {
                $item = new MaterialItem();
                $item->setFirm($firm);
                $item->setReferenceCode($implantData['referenceCode']);
                $item->setLabel($implantData['label']);
                $item->setUnit($implantData['unit']);
                $item->setIsImplant($implantData['isImplant']);
                $item->setImplantType($implantData['implantType']);
                $item->setMaterial($implantData['material']);
                $item->setDescription($implantData['description']);
                $this->em->persist($item);
                $implantCount++;
            }

            $firmCount++;
        }

        $this->em->flush();
        $io->success(sprintf('%d firmes et %d articles créés.', $firmCount, $implantCount));

        $io->table(
            ['Firme', 'Pays', 'Représentant', 'Articles'],
            array_map(fn ($f) => [
                $f['name'],
                $f['country'],
                $f['representative'],
                count($f['implants']),
            ], $firms)
        );

        return Command::SUCCESS;
    }
}
