<?php

declare(strict_types=1);

namespace IsuRide\Handlers\App;

use Fig\Http\Message\StatusCodeInterface;
use IsuRide\Database\Model\Chair;
use IsuRide\Database\Model\ChairLocation;
use IsuRide\Database\Model\RetrievedAt;
use IsuRide\Handlers\AbstractHttpHandler;
use IsuRide\Model\AppGetNearbyChairs200Response;
use IsuRide\Model\AppGetNearbyChairs200ResponseChairsInner;
use IsuRide\Model\Coordinate;
use IsuRide\Response\ErrorResponse;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GetNearbyChairs extends AbstractHttpHandler
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
        $queryParams = $request->getQueryParams();
        $latStr = $queryParams['latitude'] ?? '';
        $lonStr = $queryParams['longitude'] ?? '';
        $distanceStr = $queryParams['distance'] ?? '';
        if ($latStr === '' || $lonStr === '') {
            return (new ErrorResponse())->write(
                $response,
                StatusCodeInterface::STATUS_BAD_REQUEST,
                new \Exception('latitude or longitude is empty')
            );
        }
        if (!is_numeric($latStr)) {
            return (new ErrorResponse())->write(
                $response,
                StatusCodeInterface::STATUS_BAD_REQUEST,
                new \Exception('latitude is invalid')
            );
        }
        $lat = (int)$latStr;
        if (!is_numeric($lonStr)) {
            return (new ErrorResponse())->write(
                $response,
                StatusCodeInterface::STATUS_BAD_REQUEST,
                new \Exception('longitude is invalid')
            );
        }
        $lon = (int)$lonStr;
        $distance = 50;
        if ($distanceStr !== '') {
            if (!is_numeric($distanceStr)) {
                return (new ErrorResponse())->write(
                    $response,
                    StatusCodeInterface::STATUS_BAD_REQUEST,
                    new \Exception('distance is invalid')
                );
            }
            $distance = (int)$distanceStr;
        }
        $coordinate = new Coordinate([
            'latitude' => $lat,
            'longitude' => $lon,
        ]);
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                <<<SQL
SELECT chairs.*
FROM chairs
LEFT JOIN rides ON chairs.id = rides.chair_id
LEFT JOIN (
    SELECT 
        ride_id,
        status
    FROM ride_statuses
    WHERE (ride_id, created_at) IN (
        SELECT ride_id, MAX(created_at)
        FROM ride_statuses
        GROUP BY ride_id
    )
) ride_statuses ON rides.id = ride_statuses.ride_id
WHERE
    chairs.is_active = 1
        AND ride_statuses.status = "COMPLETED"
SQL
            );
            $stmt->execute();
            $chairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 椅子ごとのライド情報を整理
            $nearbyChairs = [];
            foreach ($chairs as $chair) {
                $chair = new Chair(
                    id: $chair['id'],
                    ownerId: $chair['owner_id'],
                    name: $chair['name'],
                    accessToken: $chair['access_token'],
                    model: $chair['model'],
                    isActive: (bool)$chair['is_active'],
                    createdAt: $chair['created_at'],
                    updatedAt: $chair['updated_at'],
                );

                // 最新の位置情報を取得
                $stmt = $this->db->prepare(
                    'SELECT * FROM chair_locations WHERE chair_id = ? ORDER BY created_at DESC LIMIT 1'
                );
                $stmt->execute([$chair->id]);
                $chairLocationResult = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$chairLocationResult) {
                    continue;
                }
                $chairLocation = new ChairLocation(
                    id: $chairLocationResult['id'],
                    chairId: $chairLocationResult['chair_id'],
                    latitude: $chairLocationResult['latitude'],
                    longitude: $chairLocationResult['longitude'],
                    createdAt: $chairLocationResult['created_at']
                );
                $distanceToChair = $this->calculateDistance(
                    $coordinate->getLatitude(),
                    $coordinate->getLongitude(),
                    $chairLocation->latitude,
                    $chairLocation->longitude
                );
                if ($distanceToChair <= $distance) {
                    $nearbyChairs[] = new AppGetNearbyChairs200ResponseChairsInner([
                        'id' => $chair->id,
                        'name' => $chair->name,
                        'model' => $chair->model,
                        'current_coordinate' => new Coordinate([
                            'latitude' => $chairLocation->latitude,
                            'longitude' => $chairLocation->longitude,
                        ]),
                    ]);
                }
            }
            $stmt = $this->db->prepare('SELECT CURRENT_TIMESTAMP(6) AS ct');
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $retrievedAt = new RetrievedAt($row['ct']);
            $this->db->commit();
            return $this->writeJson($response, new AppGetNearbyChairs200Response([
                'chairs' => $nearbyChairs,
                'retrieved_at' => $retrievedAt->unixMilliseconds(),
            ]));
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return (new ErrorResponse())->write(
                $response,
                StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                $e
            );
        }
    }
}
