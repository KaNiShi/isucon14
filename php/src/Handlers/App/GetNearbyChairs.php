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
JOIN ride_statuses ON rides.id = ride_statuses.ride_id
WHERE chairs.is_active = 1 AND ride_statuses.status = "COMPLETED"
SQL
            );
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 椅子ごとのライド情報を整理
            $chairsById = [];
            foreach ($results as $row) {
                if (!isset($chairsById[$row['id']])) {
                    // 椅子情報をオブジェクトに変換して格納
                    $chairsById[$row['id']] = new Chair(
                        id: $row['id'],
                        ownerId: $row['owner_id'],
                        name: $row['name'],
                        accessToken: $row['access_token'],
                        model: $row['model'],
                        isActive: (bool)$row['is_active'],
                        createdAt: $row['created_at'],
                        updatedAt: $row['updated_at'],
                    );
                }
            }
            $nearbyChairs = [];
            foreach ($chairsById as $chair) {
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
