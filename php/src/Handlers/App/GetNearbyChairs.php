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
            $stmt = $this->db->prepare('SELECT chairs.*, rides.id AS ride_id FROM chairs JOIN rides ON chairs.id = rides.chair_id ORDER BY rides.created_at DESC');
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $nearbyChairs = [];
            $chairs = array_reduce($results, function (array $groups, array $row) {
                $groups[$row['id']][] = $row;
                return $groups;
            }, []);
            foreach ($chairs as $results) {
                $chair = new Chair(
                    id: $results[0]['id'],
                    ownerId: $results[0]['owner_id'],
                    name: $results[0]['name'],
                    accessToken: $results[0]['access_token'],
                    model: $results[0]['model'],
                    isActive: (bool)$results[0]['is_active'],
                    createdAt: $results[0]['created_at'],
                    updatedAt: $results[0]['updated_at']
                );
                if (!$chair->isActive) {
                    continue;
                }
                $skip = false;
                foreach ($results as $result) {
                    // 過去にライドが存在し、かつ、それが完了していない場合はスキップ
                    $status = $this->getLatestRideStatus($this->db, $result['ride_id']);
                    if ($status !== 'COMPLETED') {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

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
