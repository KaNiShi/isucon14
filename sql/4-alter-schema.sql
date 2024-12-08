ALTER TABLE `chair_locations` ADD COLUMN `point` POINT GENERATED ALWAYS AS (POINT(latitude, longitude)) STORED NOT NULL COMMENT '位置';
ALTER TABLE `chair_locations` ADD SPATIAL INDEX idx_point(point);
ALTER TABLE `rides` ADD COLUMN `pickup_point` POINT GENERATED  ALWAYS AS (POINT(pickup_latitude, pickup_longitude)) STORED NOT NULL COMMENT '配車位置';
ALTER TABLE `rides` ADD SPATIAL INDEX idx_pickup_point(pickup_point);

DROP TABLE IF EXISTS chair_distances;
CREATE TABLE chair_distances
(
    chair_id VARCHAR(26) NOT NULL COMMENT '椅子ID',
    total_distance DOUBLE NOT NULL COMMENT '合計距離',
    total_distance_updated_at DATETIME(6) NOT NULL COMMENT '最終更新日時',
    PRIMARY KEY (chair_id)
);

INSERT INTO chair_distances (chair_id, total_distance, total_distance_updated_at)
SELECT chair_id,
       SUM(IFNULL(distance, 0)) AS total_distance,
       MAX(created_at) AS total_distance_updated_at
FROM (
    SELECT chair_id,
           created_at,
           ABS(latitude - LAG(latitude) OVER (PARTITION BY chair_id ORDER BY created_at)) +
           ABS(longitude - LAG(longitude) OVER (PARTITION BY chair_id ORDER BY created_at)) AS distance
    FROM chair_locations
) tmp
GROUP BY chair_id
ON DUPLICATE KEY UPDATE
    total_distance = VALUES(total_distance),
    total_distance_updated_at = VALUES(total_distance_updated_at);
