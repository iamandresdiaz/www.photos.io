<?php
declare(strict_types=1);


namespace App\Photos\File\Application\Find;


use App\Photos\File\Infrastructure\Persistence\MySqlFileRepository;
use Symfony\Component\HttpFoundation\Request;

final class FindFiles
{
    private $mySqlFileRepository;

    public function __construct(
        MySqlFileRepository $mySqlFileRepository
    ) {
        $this->mySqlFileRepository  = $mySqlFileRepository;
    }

    public function __invoke(string $text): array
    {
        $files = $this->mySqlFileRepository->cachedFind($text);

        return $files;
    }

}