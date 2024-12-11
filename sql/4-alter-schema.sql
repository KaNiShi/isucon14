ALTER TABLE `rides` ADD COLUMN `pickup_point` POINT GENERATED ALWAYS AS (POINT(pickup_latitude, pickup_longitude)) STORED NOT NULL COMMENT '配車位置';
ALTER TABLE `rides` ADD COLUMN `destination_point` POINT GENERATED ALWAYS AS (POINT(destination_latitude, destination_longitude)) STORED NOT NULL COMMENT '目的地';
ALTER TABLE `rides` ADD SPATIAL INDEX idx_pickup_point(pickup_point);

DROP TABLE IF EXISTS chair_distances;
CREATE TABLE chair_distances
(
    chair_id VARCHAR(26)   NOT NULL COMMENT '椅子ID',
    total_distance DOUBLE  NOT NULL COMMENT '合計距離',
    chair_location_id VARCHAR(26)   NOT NULL COMMENT '最新の現在位置情報ID',
    latitude   INTEGER     NOT NULL COMMENT '最新の経度',
    longitude  INTEGER     NOT NULL COMMENT '最新の緯度',
    point POINT GENERATED ALWAYS AS (POINT(latitude, longitude)) STORED NOT NULL COMMENT '位置',
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

DROP TABLE IF EXISTS chair_statuses;
CREATE TABLE chair_statuses
(
    chair_id VARCHAR(26) NOT NULL COMMENT '椅子ID',
    is_available TINYINT(1) NOT NULL COMMENT '前の配椅子が完了済か',
    PRIMARY KEY (chair_id),
    INDEX idx_chair_is_available(chair_id, is_available)
)
    COMMENT = '椅子ステータステーブル';

INSERT INTO `chair_statuses`(chair_id, is_available)
SELECT chair_id, IF(status = 'COMPLETED', 1, 0) FROM (
    SELECT rides.chair_id,ride_statuses.status, ROW_NUMBER() over (PARTITION BY rides.chair_id ORDER BY rides.created_at DESC) AS `row`
    FROM ride_statuses
    JOIN rides ON ride_statuses.ride_id = rides.id
    WHERE chair_id IS NOT NULL
) work
WHERE `row` = 1;

DROP TRIGGER IF EXISTS update_chair_status_trigger;
DELIMITER $$
CREATE TRIGGER update_chair_status_trigger AFTER INSERT ON ride_statuses FOR EACH ROW
BEGIN
    INSERT INTO chair_statuses(chair_id, is_available)
    VALUES ((SELECT chair_id FROM rides WHERE id = NEW.ride_id), IF(NEW.status = 'COMPLETED', 1, 0))
    ON DUPLICATE KEY UPDATE
        is_available = IF(NEW.status = 'COMPLETED', 1, 0);
END$$
DELIMITER ;