<?php
declare(strict_types=1);


namespace App\Photos\File\Infrastructure\Persistence;


use App\Photos\File\Domain\Entity\File;
use App\Photos\File\Domain\Repository\FileRepository;
use App\Photos\Shared\Infrastructure\Redis\RedisClient;
use Doctrine\ORM\EntityManagerInterface;

final class MySqlFileRepository implements FileRepository
{
    private $entityManager;
    private $redisClient;
    private $elasticsearchFileRepository;

    public function __construct(
        EntityManagerInterface $entityManager,
        RedisClient $redisClient,
        ElasticsearchFileRepository $elasticsearchFileRepository
    ) {
        $this->entityManager = $entityManager;
        $this->redisClient   = $redisClient;
        $this->elasticsearchFileRepository = $elasticsearchFileRepository;
    }

    public function add(File $file): void
    {
        $this->redisClient->flushAll();

        $this->entityManager->persist($file);
        $this->entityManager->flush();
    }

    public function find(array $results): array
    {
        $files =
            $this->entityManager->getRepository(File::class)->findBy(
                [
                    'tag'         => $results['tags'],
                    'description' => $results['descriptions']
                ]
            );

        $response = [];

        foreach ($files as $file)
        {
            $response[] = $this->fileToArray($file);
        }

        return $response;
    }

    public function cachedFind(string $text): array
    {
        $cacheKey = $this->redisClient::CACHE_FINGER_PRINT . '(' . $text . ')';

        $cacheItem = $this->redisClient->get($cacheKey);

        if ($cacheItem)
        {
            return json_decode($cacheItem, true);
        }

        $results = $this->elasticsearchFileRepository->find($text);
        $files = $this->find($results);

        $this->redisClient->set($cacheKey, json_encode($files));
        $this->redisClient->expire($cacheKey);

        return $files;

    }

    private function fileToArray(File $file): array
    {
        return [
            'path'        => $file->getPath(),
            'tag'         => $file->getTag(),
            'description' => $file->getDescription(),
            'type'        => $file->getType(),
            'filter'      => $file->getFilter()
        ];
    }
}
