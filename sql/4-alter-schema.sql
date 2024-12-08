ALTER TABLE `chair_locations` ADD COLUMN `point` POINT GENERATED ALWAYS AS (POINT(latitude, longitude)) STORED NOT NULL COMMENT '位置';
ALTER TABLE `chair_locations` ADD SPATIAL INDEX idx_point(point);
ALTER TABLE `rides` ADD COLUMN `pickup_point` POINT GENERATED  ALWAYS AS (POINT(pickup_latitude, pickup_longitude)) STORED NOT NULL COMMENT '配車位置';
ALTER TABLE `rides` ADD SPATIAL INDEX idx_pickup_point(pickup_point);
