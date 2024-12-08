ALTER TABLE `chair_locations` ADD COLUMN `point` POINT GENERATED ALWAYS AS (POINT(latitude, longitude)) STORED NOT NULL COMMENT '位置';
ALTER TABLE `chair_locations` ADD SPATIAL INDEX idx_point(point);
ALTER TABLE `rides` ADD COLUMN `pickup_point` POINT GENERATED  ALWAYS AS (POINT(pickup_latitude, pickup_longitude)) STORED NOT NULL COMMENT '配車位置';
ALTER TABLE `rides` ADD SPATIAL INDEX idx_pickup_point(pickup_point);

DROP TABLE IF EXISTS chair_distances;
CREATE TABLE chair_distances
(
    chair_id VARCHAR(26)   NOT NULL COMMENT '椅子ID',
    total_distance DOUBLE  NOT NULL COMMENT '合計距離',
    chair_location_id VARCHAR(26)   NOT NULL COMMENT '最新の現在位置情報ID',
    latitude   INTEGER     NOT NULL COMMENT '最新の経度',
    longitude  INTEGER     NOT NULL COMMENT '最新の緯度',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT '登録日時',
    PRIMARY KEY (chair_id)
);

INSERT INTO chair_distances (chair_id, total_distance, chair_location_id, latitude, longitude, created_at)
SELECT tmp.chair_id,
       SUM(IFNULL(distance, 0)) AS total_distance,
       latest_location.id AS chair_location_id,
       latest_location.latitude,
       latest_location.longitude,
       MAX(created_at) AS created_at
FROM (
    SELECT chair_id,
           created_at,
           ABS(latitude - LAG(latitude) OVER (PARTITION BY chair_id ORDER BY created_at)) +
           ABS(longitude - LAG(longitude) OVER (PARTITION BY chair_id ORDER BY created_at)) AS distance
    FROM chair_locations
) tmp
JOIN (
    SELECT id, chair_id, latitude, longitude
    FROM chair_locations
    WHERE (chair_id, created_at) IN (
        SELECT chair_id, MAX(created_at)
        FROM chair_locations
        GROUP BY chair_id
    )
) latest_location ON tmp.chair_id = latest_location.chair_id
GROUP BY
    tmp.chair_id,
    latest_location.id,
    latest_location.latitude,
    latest_location.longitude
ON DUPLICATE KEY UPDATE
    total_distance = VALUES(total_distance),
    chair_location_id = VALUES(chair_location_id),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    created_at = VALUES(created_at);
