<?php
declare(strict_types=1);


namespace App\Photos\Shared\Infrastructure\RabbitMQ;


use App\Photos\File\Domain\Entity\File;
use App\Photos\File\Domain\ValueObject\FileDescription;
use App\Photos\File\Domain\ValueObject\FileFilter;
use App\Photos\File\Domain\ValueObject\FilePath;
use App\Photos\File\Domain\ValueObject\FileTag;
use App\Photos\File\Domain\ValueObject\FileType;
use App\Photos\File\Infrastructure\Persistence\ElasticsearchFileRepository;
use App\Photos\File\Infrastructure\Persistence\MySqlFileRepository;
use App\Photos\Shared\Domain\Filter\FilterFactory;
use App\Photos\Shared\Infrastructure\SimpleImage\SimpleImageBuilder;
use Bunny\Channel;
use Bunny\Message;
use Bunny\Client;
use Bunny\Exception\ClientException;
use DateTime;

final class RabbitMQBunnyConsumer
{
    private $rabbitMQBunnyClient;
    private $simpleImageBuilder;
    private $filterFactory;
    private $mySqlFileRepository;
    private $elasticsearchFileRepository;

    public function __construct(
        RabbitMQBunnyClient $rabbitMQBunnyClient,
        SimpleImageBuilder $simpleImageBuilder,
        MySqlFileRepository $mySqlFileRepository,
        ElasticsearchFileRepository $elasticsearchFileRepository,
        FilterFactory $filterFactory
    ) {
        $this->rabbitMQBunnyClient = $rabbitMQBunnyClient;
        $this->simpleImageBuilder  = $simpleImageBuilder;
        $this->filterFactory       = $filterFactory;
        $this->mySqlFileRepository  = $mySqlFileRepository;
        $this->elasticsearchFileRepository = $elasticsearchFileRepository;
    }

    public function __invoke()
    {
        $client  = $this->rabbitMQBunnyClient->client();

        try {
            $client->connect();
        } catch (ClientException $clientException)
        {
            throw $clientException;
        }

        $channel = $client->channel();
        $channel->queueDeclare(RabbitMQBunnyClient::QUEUE);
        $channel->queueBind(RabbitMQBunnyClient::QUEUE, RabbitMQBunnyClient::EXCHANGE);

        $channel->qos(
            0,
            1
        );

        $channel->run(
            function (Message $message, Channel $channel, Client $bunny) {
                $fileInfo = $this->jsonToArray($message);

                if ($fileInfo) {
                    $channel->ack($message);
                    echo 'applying ' . $fileInfo['filter_to_apply'] . ' filter to ' . $fileInfo['original_path'] . PHP_EOL;
                    $this->applyFilter($fileInfo);
                    $file = $this->getFile($fileInfo);
                    $this->mySqlFileRepository->add($file);
                    $this->elasticsearchFileRepository->add($file);
                    echo 'Finished' . PHP_EOL;
                    return;
                }

                $channel->nack($message);
            },
            RabbitMQBunnyClient::QUEUE
        );
    }

    private function jsonToArray(Message $message): array
    {
        return json_decode($message->content, true);
    }

    private function applyFilter($fileInfo): void
    {
        $this->filterFactory->create(
            $this->simpleImageBuilder->image(),
            $fileInfo
        );
    }

    private function getFile(array $fileInfo): File
    {
        $tag         = new FileTag($fileInfo['tag']);
        $description = new FileDescription($fileInfo['description']);
        $type        = new FileType($fileInfo['type']);
        $path        = new FilePath($fileInfo['new_path']);
        $filter      = new FileFilter($fileInfo['filter_to_apply']);
        $createdAt   = new DateTime('now');

        return new File($tag, $description, $type, $path, $filter, $createdAt);
    }
}