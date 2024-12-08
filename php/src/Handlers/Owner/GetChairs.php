<?php

declare(strict_types=1);

namespace IsuRide\Handlers\Owner;

use Fig\Http\Message\StatusCodeInterface;
use IsuRide\Database\Model\ChairWithDetail;
use IsuRide\Database\Model\Owner;
use IsuRide\Handlers\AbstractHttpHandler;
use IsuRide\Model\OwnerGetChairs200Response;
use IsuRide\Model\OwnerGetChairs200ResponseChairsInner;
use IsuRide\Response\ErrorResponse;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GetChairs extends AbstractHttpHandler
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<string, string> $args
     * @return ResponseInterface
     * @throws \Exception
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $owner = $request->getAttribute('owner');
        assert($owner instanceof Owner);
        /** @var ChairWithDetail[] $chairs */
        $chairs = [];
        try {
            $stmt = $this->db->prepare(
                <<<SQL
SELECT id,
       owner_id,
       name,
       access_token,
       model,
       is_active,
       chairs.created_at,
       updated_at,
       IFNULL(chair_distances.total_distance, 0) AS total_distance,
       chair_distances.created_at AS total_distance_updated_at
FROM chairs
LEFT JOIN chair_distances ON chairs.id = chair_distances.chair_id
WHERE owner_id = ?
SQL
            );
            $stmt->execute([$owner->id]);
            $chairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return (new ErrorResponse())->write(
                $response,
                StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                $e
            );
        }
        $res = new OwnerGetChairs200Response();
        $ownerChairs = [];
        foreach ($chairs as $row) {
            $chair = new ChairWithDetail(
                id: $row['id'],
                ownerId: $row['owner_id'],
                name: $row['name'],
                accessToken: $row['access_token'],
                model: $row['model'],
                isActive: (bool)$row['is_active'],
                createdAt: $row['created_at'],
                updatedAt: $row['updated_at'],
                totalDistance: (int)$row['total_distance'],
                totalDistanceUpdatedAt: $row['total_distance_updated_at']
            );
            $ownerChair = new OwnerGetChairs200ResponseChairsInner();
            $ownerChair->setId($chair->id)
                ->setName($chair->name)
                ->setModel($chair->model)
                ->setActive($chair->isActive)
                ->setRegisteredAt($chair->createdAtUnixMilliseconds())
                ->setTotalDistance($chair->totalDistance);
            if ($chair->isTotalDistanceUpdatedAt()) {
                $ownerChair->setTotalDistanceUpdatedAt($chair->totalDistanceUpdatedAtUnixMilliseconds());
            }
            $ownerChairs[] = $ownerChair;
        }
        return $this->writeJson($response, $res->setChairs($ownerChairs));
    }
}
