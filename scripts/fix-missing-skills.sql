-- Fix missing skills for existing users
-- This initializes skills for users who registered before skill initialization was added

-- Insert skills for all users who don't have them
INSERT INTO user_skills (user_id, skill_id, level, xp, updated_at)
SELECT
    u.id as user_id,
    s.id as skill_id,
    1 as level,
    0 as xp,
    NOW() as updated_at
FROM users u
CROSS JOIN skills s
WHERE NOT EXISTS (
    SELECT 1
    FROM user_skills us
    WHERE us.user_id = u.id AND us.skill_id = s.id
);

-- Verify the fix
SELECT
    u.username,
    COUNT(us.user_id) as skill_count
FROM users u
LEFT JOIN user_skills us ON u.id = us.user_id
GROUP BY u.id, u.username
ORDER BY u.id;
