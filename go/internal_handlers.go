package main

import (
	"database/sql"
	"errors"
	"net/http"
	"sort"
)

// このAPIをインスタンス内から一定間隔で叩かせることで、椅子とライドをマッチングさせる
func internalGetMatching(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	// MEMO: 一旦最も待たせているリクエストに適当な空いている椅子マッチさせる実装とする。おそらくもっといい方法があるはず…
	rides := []Ride{}
	if err := db.SelectContext(ctx, &rides, `SELECT * FROM rides WHERE chair_id IS NULL ORDER BY created_at LIMIT 100`); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		writeError(w, http.StatusInternalServerError, err)
		return
	}

	for _, ride := range rides {
		matchedList := []MacingChair{}
		if err := db.SelectContext(ctx, &matchedList, `SELECT chairs.*, chair_models.speed, ST_Distance((SELECT pickup_point FROM rides WHERE id = ?), chair_distances.point) AS distance
FROM chairs
JOIN chair_distances ON chairs.id = chair_distances.chair_id
JOIN chair_models ON chairs.model = chair_models.name
WHERE is_active = TRUE
ORDER BY distance`, ride.ID); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				w.WriteHeader(http.StatusNoContent)
				return
			}
			writeError(w, http.StatusInternalServerError, err)
			return
		}

		candidates := make([]MacingChair, 0, 30)
		for _, matched := range matchedList {
			empty := false
			if err := db.GetContext(ctx, &empty, "SELECT COUNT(*) = 0 FROM (SELECT COUNT(chair_sent_at) = 6 AS completed FROM ride_statuses WHERE ride_id IN (SELECT id FROM rides WHERE chair_id = ?) GROUP BY ride_id) is_completed WHERE completed = FALSE", matched.ID); err != nil {
				writeError(w, http.StatusInternalServerError, err)
				return
			}
			if empty {
				candidates = append(candidates, matched)
				if len(candidates) == 30 {
					break
				}
			}
		}

		if len(candidates) == 0 {
			break
		}

		distance := 0.0
		if err := db.GetContext(ctx, &distance, "SELECT ST_Distance(rides.pickup_point, rides.destination_point) AS distance FROM rides WHERE id = ?", ride.ID); err != nil {
			writeError(w, http.StatusInternalServerError, err)
			return
		}

		scoredList := make([]struct {
			Chair MacingChair
			Score float64
		}, 0, len(candidates))
		for _, candidate := range candidates {
			scoredList = append(scoredList, struct {
				Chair MacingChair
				Score float64
			}{Chair: candidate, Score: distance/float64(candidate.Speed) + candidate.Distance/float64(candidate.Speed)})
		}
		sort.Slice(scoredList, func(i, j int) bool { return scoredList[i].Score < scoredList[j].Score })

		chair := scoredList[0].Chair
		if _, err := db.ExecContext(ctx, "UPDATE rides SET chair_id = ? WHERE id = ?", chair.ID, ride.ID); err != nil {
			writeError(w, http.StatusInternalServerError, err)
			return
		}
	}

	w.WriteHeader(http.StatusNoContent)
}
