<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column]
    #[Assert\Range(min: 5, max: 200)]
    private int $itemsPerPage = 20;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $backupDirectory = null;

    #[ORM\Column(length: 10, options: ['default' => 'en'])]
    #[Assert\Choice(choices: ['en', 'fr'])]
    private string $locale = 'en';

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    #[Assert\Currency]
    private string $currency = 'EUR';

    #[ORM\Column(length: 10, options: ['default' => 'auto'])]
    #[Assert\Choice(choices: ['light', 'dark', 'auto'])]
    private string $theme = 'auto';

    public function getId(): int
    {
        return $this->id;
    }

    public function getItemsPerPage(): int
    {
        return $this->itemsPerPage;
    }

    public function setItemsPerPage(int $itemsPerPage): static
    {
        $this->itemsPerPage = $itemsPerPage;

        return $this;
    }

    public function getBackupDirectory(): ?string
    {
        return $this->backupDirectory;
    }

    public function setBackupDirectory(?string $backupDirectory): static
    {
        $this->backupDirectory = $backupDirectory;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }
}
