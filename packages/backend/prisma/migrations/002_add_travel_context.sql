-- Add travel context fields to battles table
ALTER TABLE battles
ADD COLUMN travel_destination VARCHAR(80),
ADD COLUMN travel_progress INTEGER,
ADD COLUMN travel_distance INTEGER,
ADD COLUMN travel_start_location VARCHAR(80);
