-- Seed initial data for Eldvar

-- 1. Create Skills
INSERT INTO skills (skey, name) VALUES
('attack', 'Attack'),
('strength', 'Strength'),
('defense', 'Defense'),
('health', 'Health'),
('range', 'Range'),
('magic', 'Magic'),
('mining', 'Mining'),
('crafting', 'Crafting'),
('blacksmithing', 'Blacksmithing')
ON CONFLICT (skey) DO NOTHING;

-- 2. Create XP Thresholds (OSRS-style, levels 1-99)
INSERT INTO xp_thresholds (level, xp_required) VALUES
(1, 0), (2, 83), (3, 174), (4, 276), (5, 388), (6, 512), (7, 650), (8, 801), (9, 969), (10, 1154),
(11, 1358), (12, 1584), (13, 1833), (14, 2107), (15, 2411), (16, 2746), (17, 3115), (18, 3523), (19, 3973), (20, 4470),
(21, 5018), (22, 5624), (23, 6291), (24, 7028), (25, 7842), (26, 8740), (27, 9730), (28, 10824), (29, 12031), (30, 13363),
(31, 14833), (32, 16456), (33, 18247), (34, 20224), (35, 22406), (36, 24815), (37, 27473), (38, 30408), (39, 33648), (40, 37224),
(41, 41171), (42, 45529), (43, 50339), (44, 55649), (45, 61512), (46, 67983), (47, 75127), (48, 83014), (49, 91721), (50, 101333),
(51, 111945), (52, 123660), (53, 136594), (54, 150872), (55, 166636), (56, 184040), (57, 203254), (58, 224466), (59, 247886), (60, 273742),
(61, 302288), (62, 333804), (63, 368599), (64, 407015), (65, 449428), (66, 496254), (67, 547953), (68, 605032), (69, 668051), (70, 737627),
(71, 814445), (72, 899257), (73, 992895), (74, 1096278), (75, 1210421), (76, 1336443), (77, 1475581), (78, 1629200), (79, 1798808), (80, 1986068),
(81, 2192818), (82, 2421087), (83, 2673114), (84, 2951373), (85, 3258594), (86, 3597792), (87, 3972294), (88, 4385776), (89, 4842295), (90, 5346332),
(91, 5902831), (92, 6517253), (93, 7195629), (94, 7944614), (95, 8771558), (96, 9684577), (97, 10692629), (98, 11805606), (99, 13034431)
ON CONFLICT (level) DO NOTHING;

-- 3. Create Game Settings
INSERT INTO game_settings (key, value, updated_at) VALUES
('wins_required_per_floor', '3', NOW()),
('void_step_per_floor', '3', NOW()),
('void_cap_percent', '60', NOW()),
('player_acc_pen_divisor', '5.0', NOW()),
('player_dmg_min_multiplier', '0.70', NOW()),
('player_dmg_divisor', '200.0', NOW()),
('mob_dmg_divisor', '200.0', NOW()),
('reward_xp_per_floor_pct', '5.0', NOW()),
('reward_gold_per_floor_pct', '4.0', NOW())
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW();

-- 4. Create World Areas
INSERT INTO world_areas (slug, name, short_blurb, created_at) VALUES
('mystic-harshlands', 'Mystic Harshlands', 'A desolate wasteland where magic runs wild', NOW()),
('yulon-forest', 'Yulon Forest', 'Ancient woods teeming with mysterious creatures', NOW()),
('reichal', 'Reichal', 'The once-great kingdom now in ruins', NOW()),
('undar', 'Undar', 'Underground caverns filled with treasures and dangers', NOW()),
('frostbound-tundra', 'Frostbound Tundra', 'Frozen wastes where only the strong survive', NOW())
ON CONFLICT (slug) DO NOTHING;

-- 5. Create Sample Mobs
INSERT INTO mobs (name, level, hp, attack, defense, magic, range, reward_xp, reward_gold, min_floor, max_floor) VALUES
('Goblin Scout', 1, 30, 5, 3, 1, 2, 50, 10, 1, 5),
('Dark Wolf', 3, 50, 8, 5, 2, 3, 75, 15, 3, 10),
('Shadow Mage', 5, 70, 6, 4, 12, 4, 100, 25, 5, 15),
('Frost Giant', 10, 150, 20, 15, 5, 8, 250, 50, 10, 25),
('Void Wraith', 20, 300, 40, 30, 45, 35, 500, 100, 20, 999);

-- 6. Admin user can be created through registration UI
