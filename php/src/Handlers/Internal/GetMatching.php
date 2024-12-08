<?php

declare(strict_types=1);

namespace IsuRide\Handlers\Internal;

use IsuRide\Handlers\AbstractHttpHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PDO;

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
        $stmt = $this->db->prepare('SELECT * FROM rides WHERE chair_id IS NULL ORDER BY created_at LIMIT 100');
        $stmt->execute();
        $rides = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rides) {
            return $this->writeNoContent($response);
        }

        foreach ($rides as $ride) {
            $stmt = $this->db->prepare('
SELECT chairs.*, chair_models.speed, ST_Distance((SELECT pickup_point FROM rides WHERE id = ?), chair_distances.point) AS distance
FROM chairs
JOIN chair_distances ON chairs.id = chair_distances.chair_id
JOIN chair_models ON chairs.model = chair_models.name
WHERE is_active = TRUE
ORDER BY distance
            ');
            $stmt->execute([$ride['id']]);
            $matched = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$matched) {
                return $this->writeNoContent($response);
            }

            $candidates = [];
            foreach ($matched as $item) {
                $stmt = $this->db->prepare(
                    'SELECT COUNT(*) = 0 FROM (SELECT COUNT(chair_sent_at) = 6 AS completed FROM ride_statuses WHERE ride_id IN (SELECT id FROM rides WHERE chair_id = ?) GROUP BY ride_id) is_completed WHERE completed = FALSE'
                );
                $stmt->execute([$item['id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $empty = $result['COUNT(*) = 0'];
                if ($empty) {
                    $candidates[] = $item;
                    if (count($candidates) == 30) {
                        break;
                    }
                }
            }

            if (count($candidates) == 0) {
                break;
            }

            $stmt = $this->db->prepare('SELECT ST_Distance(rides.pickup_point, rides.destination_point) AS distance FROM rides WHERE id = ?');
            $stmt->execute([$ride['id']]);
            $distance = (double) $stmt->fetch(PDO::FETCH_ASSOC)['distance'];
            $newCandidates = [];
            $scores = [];
            foreach ($candidates as $candidate) {
                $score = $distance / $candidate['speed'] + $candidate['distance'] / $candidate['speed'];
                if ($score < 3.5) {
                    continue;
                }

                $newCandidates[] = $candidate;
                $scores[] = $score;
            }

            if ($newCandidates === []) {
                $item = $candidates[0];
            } else {
                array_multisort($scores, SORT_ASC, SORT_NUMERIC, $newCandidates);
                $item = $newCandidates[0];
            }

            $stmt = $this->db->prepare('UPDATE rides SET chair_id = ? WHERE id = ?');
            $stmt->execute([$item['id'], $ride['id']]);
        }

        return $this->writeNoContent($response);
    }
}
