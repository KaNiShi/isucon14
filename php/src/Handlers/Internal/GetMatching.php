<?php

declare(strict_types=1);

namespace IsuRide\Handlers\Internal;

use IsuRide\Handlers\AbstractHttpHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;
use Psr\Log\LoggerInterface;

// このAPIをインスタンス内から一定間隔で叩かせることで、椅子とライドをマッチングさせる
class GetMatching extends AbstractHttpHandler
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        // MEMO: 一旦最も待たせているリクエストに適当な空いている椅子マッチさせる実装とする。おそらくもっといい方法があるはず…
        $stmt = $this->db->prepare('SELECT *, ST_Distance(pickup_point, destination_point) AS distance FROM rides WHERE chair_id IS NULL ORDER BY created_at LIMIT 100');
        $stmt->execute();
        $rides = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rides) {
            return $this->writeNoContent($response);
        }

        $stmt = $this->db->prepare('
SELECT chairs.*, chair_models.speed, chair_distances.latitude, chair_distances.longitude FROM chairs
    JOIN chair_models ON chairs.model = chair_models.name
    JOIN chair_distances ON chairs.id = chair_distances.chair_id
    LEFT JOIN chair_statuses ON chairs.id = chair_statuses.chair_id
WHERE is_active = TRUE AND (chair_statuses.is_available IS NULL OR chair_statuses.is_available = 1)
');
        $stmt->execute();
        $chairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$chairs) {
            return $this->writeNoContent($response);
        }

        // 取得できたリストのうち移動距離が長いものから処理をする
        array_multisort(array_column($rides, 'distance'), SORT_DESC, SORT_NUMERIC, $rides);
        foreach ($rides as $ride) {
            $chairScores = array_map(
                fn($chair) => ($ride['distance'] + sqrt(pow($chair['latitude'] - $ride['pickup_latitude'], 2) + pow($chair['longitude'] - $ride['pickup_longitude'], 2))) / $chair['speed'],
                $chairs
            );

            array_multisort($chairScores, SORT_ASC, SORT_NUMERIC, $chairs);
            $chair = $chairs[0];
            $stmt = $this->db->prepare('UPDATE rides SET chair_id = ? WHERE id = ?');
            $stmt->execute([$chair['id'], $ride['id']]);

            // 配椅子したものは除外
            unset($chairs[0]);
            if (!$chairs) {
                break;
            }
        }

        return $this->writeNoContent($response);
    }
}
