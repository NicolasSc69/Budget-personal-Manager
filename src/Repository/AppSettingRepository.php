<?php

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppSetting>
 */
class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    public function getSettings(): AppSetting
    {
        $settings = $this->find(1);

        if (!$settings) {
            $settings = new AppSetting();
            $em = $this->getEntityManager();
            $em->persist($settings);
            $em->flush();
        }

        return $settings;
    }

    /**
     * Reads the settings row without creating it, safe to call from within a flush listener.
     */
    public function findExistingSettings(): ?AppSetting
    {
        return $this->find(1);
    }
}
