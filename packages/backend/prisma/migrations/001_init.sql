-- CreateEnum
CREATE TYPE "user_role" AS ENUM ('PLAYER', 'SUPPORTER', 'HELPER', 'MODERATOR', 'ADMIN', 'GOVERNOR', 'LIBRARIAN');

-- CreateEnum
CREATE TYPE "combat_style" AS ENUM ('ATTACK', 'STRENGTH', 'DEFENSE', 'RANGE', 'MAGIC');

-- CreateEnum
CREATE TYPE "battle_status" AS ENUM ('ONGOING', 'WON', 'LOST', 'FLED');

-- CreateEnum
CREATE TYPE "actor" AS ENUM ('PLAYER', 'MOB');

-- CreateEnum
CREATE TYPE "guild_member_role" AS ENUM ('LEADER', 'OFFICER', 'MEMBER');

-- CreateEnum
CREATE TYPE "item_type" AS ENUM ('WEAPON', 'ARMOR', 'CONSUMABLE', 'MATERIAL', 'QUEST', 'MISC');

-- CreateEnum
CREATE TYPE "item_rarity" AS ENUM ('COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'LEGENDARY', 'MYTHIC');

-- CreateEnum
CREATE TYPE "chat_channel" AS ENUM ('GLOBAL', 'ADS', 'SUPPORT', 'GUILD');

-- CreateEnum
CREATE TYPE "report_status" AS ENUM ('OPEN', 'RESOLVED', 'INVALID');

-- CreateEnum
CREATE TYPE "wiki_status" AS ENUM ('DRAFT', 'PUBLISHED');

-- CreateEnum
CREATE TYPE "ticket_status" AS ENUM ('OPEN', 'ANSWERED', 'CLOSED');

-- CreateEnum
CREATE TYPE "ticket_priority" AS ENUM ('LOW', 'NORMAL', 'HIGH');

-- CreateEnum
CREATE TYPE "news_type" AS ENUM ('NEWS', 'PATCH');

-- CreateTable
CREATE TABLE "users" (
    "id" SERIAL NOT NULL,
    "username" VARCHAR(50) NOT NULL,
    "password" VARCHAR(255) NOT NULL,
    "email" VARCHAR(190),
    "role" "user_role" NOT NULL DEFAULT 'PLAYER',
    "display_name" VARCHAR(120),
    "bio" TEXT,
    "avatar_url" VARCHAR(255),
    "banner_url" VARCHAR(255),
    "status_text" VARCHAR(190),
    "level" INTEGER NOT NULL DEFAULT 1,
    "overall_xp" INTEGER NOT NULL DEFAULT 0,
    "current_floor" INTEGER NOT NULL DEFAULT 1,
    "deepest_floor" INTEGER NOT NULL DEFAULT 1,
    "current_area_code" VARCHAR(80),
    "gold" INTEGER NOT NULL DEFAULT 0,
    "verified" BOOLEAN NOT NULL DEFAULT false,
    "last_seen" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "users_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "skills" (
    "id" SERIAL NOT NULL,
    "skey" VARCHAR(32) NOT NULL,
    "name" VARCHAR(64) NOT NULL,

    CONSTRAINT "skills_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "user_skills" (
    "user_id" INTEGER NOT NULL,
    "skill_id" INTEGER NOT NULL,
    "level" INTEGER NOT NULL DEFAULT 1,
    "xp" INTEGER NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "user_skills_pkey" PRIMARY KEY ("user_id","skill_id")
);

-- CreateTable
CREATE TABLE "xp_thresholds" (
    "level" INTEGER NOT NULL,
    "xp_required" INTEGER NOT NULL,

    CONSTRAINT "xp_thresholds_pkey" PRIMARY KEY ("level")
);

-- CreateTable
CREATE TABLE "mobs" (
    "id" SERIAL NOT NULL,
    "name" VARCHAR(120) NOT NULL,
    "level" INTEGER NOT NULL,
    "hp" INTEGER NOT NULL,
    "attack" INTEGER NOT NULL,
    "defense" INTEGER NOT NULL,
    "magic" INTEGER NOT NULL,
    "range" INTEGER NOT NULL,
    "reward_xp" INTEGER NOT NULL,
    "reward_gold" INTEGER NOT NULL,
    "min_floor" INTEGER NOT NULL DEFAULT 1,
    "max_floor" INTEGER NOT NULL DEFAULT 999,

    CONSTRAINT "mobs_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "battles" (
    "id" BIGSERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "mob_id" INTEGER NOT NULL,
    "char_name" VARCHAR(120) NOT NULL,
    "char_hp_current" INTEGER NOT NULL,
    "char_hp_max" INTEGER NOT NULL,
    "mob_name" VARCHAR(120) NOT NULL,
    "mob_hp_current" INTEGER NOT NULL,
    "mob_hp_max" INTEGER NOT NULL,
    "reward_xp" INTEGER NOT NULL,
    "reward_gold" INTEGER NOT NULL,
    "floor" INTEGER NOT NULL DEFAULT 1,
    "void_intensity" INTEGER NOT NULL DEFAULT 0,
    "combat_style" "combat_style" NOT NULL,
    "status" "battle_status" NOT NULL DEFAULT 'ONGOING',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "battles_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "battle_turns" (
    "id" BIGSERIAL NOT NULL,
    "battle_id" BIGINT NOT NULL,
    "turn_no" INTEGER NOT NULL,
    "actor" "actor" NOT NULL,
    "action" VARCHAR(32) NOT NULL,
    "damage" INTEGER NOT NULL,
    "char_hp_after" INTEGER NOT NULL,
    "mob_hp_after" INTEGER NOT NULL,
    "log_text" TEXT NOT NULL,

    CONSTRAINT "battle_turns_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "guilds" (
    "id" BIGSERIAL NOT NULL,
    "name" VARCHAR(80) NOT NULL,
    "tag" VARCHAR(10) NOT NULL,
    "description" TEXT,
    "owner_id" INTEGER,
    "emblem" VARCHAR(255),
    "is_recruiting" BOOLEAN NOT NULL DEFAULT true,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "guilds_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "guild_members" (
    "id" BIGSERIAL NOT NULL,
    "guild_id" BIGINT NOT NULL,
    "user_id" INTEGER NOT NULL,
    "role" "guild_member_role" NOT NULL DEFAULT 'MEMBER',
    "joined_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "guild_members_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "items" (
    "id" SERIAL NOT NULL,
    "name" VARCHAR(120) NOT NULL,
    "slug" VARCHAR(140) NOT NULL,
    "type" "item_type" NOT NULL DEFAULT 'MISC',
    "rarity" "item_rarity" NOT NULL DEFAULT 'COMMON',
    "description" TEXT,
    "icon_path" VARCHAR(255),
    "stackable" BOOLEAN NOT NULL DEFAULT true,
    "max_stack" INTEGER NOT NULL DEFAULT 99,
    "base_value" INTEGER NOT NULL DEFAULT 0,
    "bind_on_pickup" BOOLEAN NOT NULL DEFAULT false,
    "usable" BOOLEAN NOT NULL DEFAULT false,
    "level_requirement" INTEGER NOT NULL DEFAULT 1,
    "use_script" VARCHAR(128),
    "use_payload" JSONB,
    "created_by" INTEGER,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "items_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "item_modifiers" (
    "id" SERIAL NOT NULL,
    "item_id" INTEGER NOT NULL,
    "stat_name" VARCHAR(64) NOT NULL,
    "flat_amount" INTEGER NOT NULL DEFAULT 0,
    "percent_amount" DECIMAL(6,2) NOT NULL DEFAULT 0,

    CONSTRAINT "item_modifiers_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "item_images" (
    "id" SERIAL NOT NULL,
    "item_id" INTEGER NOT NULL,
    "path" VARCHAR(255) NOT NULL,
    "alt_text" VARCHAR(190) NOT NULL,
    "is_primary" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "item_images_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "chat_messages" (
    "id" BIGSERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "channel" "chat_channel" NOT NULL DEFAULT 'GLOBAL',
    "body" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "chat_messages_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "chat_message_reports" (
    "id" SERIAL NOT NULL,
    "message_id" BIGINT NOT NULL,
    "reporter_user_id" INTEGER NOT NULL,
    "reason" VARCHAR(255) NOT NULL,
    "status" "report_status" NOT NULL DEFAULT 'OPEN',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "handled_by" INTEGER,
    "handled_at" TIMESTAMP(3),

    CONSTRAINT "chat_message_reports_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "tavern_posts" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER NOT NULL,
    "title" VARCHAR(120) NOT NULL,
    "body" TEXT NOT NULL,
    "expires_at" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "tavern_posts_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "profile_comments" (
    "id" SERIAL NOT NULL,
    "profile_user_id" INTEGER NOT NULL,
    "author_user_id" INTEGER NOT NULL,
    "body" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "profile_comments_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "wiki_pages" (
    "id" SERIAL NOT NULL,
    "slug" VARCHAR(150) NOT NULL,
    "title" VARCHAR(150) NOT NULL,
    "content" TEXT NOT NULL,
    "image_path" VARCHAR(255),
    "status" "wiki_status" NOT NULL DEFAULT 'DRAFT',
    "author_id" INTEGER NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "wiki_pages_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "wiki_categories" (
    "id" SERIAL NOT NULL,
    "slug" VARCHAR(150) NOT NULL,
    "name" VARCHAR(150) NOT NULL,
    "description" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "wiki_categories_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "wiki_page_categories" (
    "page_id" INTEGER NOT NULL,
    "category_id" INTEGER NOT NULL,

    CONSTRAINT "wiki_page_categories_pkey" PRIMARY KEY ("page_id","category_id")
);

-- CreateTable
CREATE TABLE "world_areas" (
    "id" SERIAL NOT NULL,
    "slug" VARCHAR(80) NOT NULL,
    "name" VARCHAR(120) NOT NULL,
    "short_blurb" VARCHAR(255),
    "image_path" VARCHAR(255),
    "lore_text" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "world_areas_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "world_towers" (
    "id" SERIAL NOT NULL,
    "area_id" INTEGER NOT NULL,
    "name" VARCHAR(120) NOT NULL,
    "description" TEXT,
    "min_floor" INTEGER NOT NULL DEFAULT 1,
    "max_floor" INTEGER NOT NULL DEFAULT 50,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "world_towers_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "game_settings" (
    "key" VARCHAR(100) NOT NULL,
    "value" VARCHAR(255) NOT NULL,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "game_settings_pkey" PRIMARY KEY ("key")
);

-- CreateTable
CREATE TABLE "support_tickets" (
    "id" SERIAL NOT NULL,
    "user_id" INTEGER,
    "email" VARCHAR(190) NOT NULL,
    "subject" VARCHAR(190) NOT NULL,
    "message" TEXT NOT NULL,
    "status" "ticket_status" NOT NULL DEFAULT 'OPEN',
    "priority" "ticket_priority" NOT NULL DEFAULT 'NORMAL',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "support_tickets_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "support_replies" (
    "id" SERIAL NOT NULL,
    "ticket_id" INTEGER NOT NULL,
    "user_id" INTEGER,
    "author_role" VARCHAR(32) NOT NULL,
    "message" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "support_replies_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "news" (
    "id" SERIAL NOT NULL,
    "title" VARCHAR(190) NOT NULL,
    "slug" VARCHAR(190) NOT NULL,
    "body" TEXT NOT NULL,
    "type" "news_type" NOT NULL DEFAULT 'NEWS',
    "author_id" INTEGER NOT NULL,
    "published_at" TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "news_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "users_username_key" ON "users"("username");

-- CreateIndex
CREATE UNIQUE INDEX "skills_skey_key" ON "skills"("skey");

-- CreateIndex
CREATE INDEX "battles_user_id_status_idx" ON "battles"("user_id", "status");

-- CreateIndex
CREATE INDEX "battles_floor_idx" ON "battles"("floor");

-- CreateIndex
CREATE INDEX "battle_turns_battle_id_idx" ON "battle_turns"("battle_id");

-- CreateIndex
CREATE UNIQUE INDEX "guilds_name_key" ON "guilds"("name");

-- CreateIndex
CREATE UNIQUE INDEX "guild_members_guild_id_user_id_key" ON "guild_members"("guild_id", "user_id");

-- CreateIndex
CREATE UNIQUE INDEX "items_slug_key" ON "items"("slug");

-- CreateIndex
CREATE INDEX "chat_messages_channel_created_at_idx" ON "chat_messages"("channel", "created_at");

-- CreateIndex
CREATE INDEX "chat_message_reports_message_id_idx" ON "chat_message_reports"("message_id");

-- CreateIndex
CREATE INDEX "chat_message_reports_status_idx" ON "chat_message_reports"("status");

-- CreateIndex
CREATE INDEX "tavern_posts_created_at_idx" ON "tavern_posts"("created_at");

-- CreateIndex
CREATE UNIQUE INDEX "wiki_pages_slug_key" ON "wiki_pages"("slug");

-- CreateIndex
CREATE UNIQUE INDEX "wiki_categories_slug_key" ON "wiki_categories"("slug");

-- CreateIndex
CREATE UNIQUE INDEX "world_areas_slug_key" ON "world_areas"("slug");

-- CreateIndex
CREATE UNIQUE INDEX "news_slug_key" ON "news"("slug");

-- AddForeignKey
ALTER TABLE "user_skills" ADD CONSTRAINT "user_skills_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "user_skills" ADD CONSTRAINT "user_skills_skill_id_fkey" FOREIGN KEY ("skill_id") REFERENCES "skills"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "battles" ADD CONSTRAINT "battles_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "battles" ADD CONSTRAINT "battles_mob_id_fkey" FOREIGN KEY ("mob_id") REFERENCES "mobs"("id") ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "battle_turns" ADD CONSTRAINT "battle_turns_battle_id_fkey" FOREIGN KEY ("battle_id") REFERENCES "battles"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "guilds" ADD CONSTRAINT "guilds_owner_id_fkey" FOREIGN KEY ("owner_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "guild_members" ADD CONSTRAINT "guild_members_guild_id_fkey" FOREIGN KEY ("guild_id") REFERENCES "guilds"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "guild_members" ADD CONSTRAINT "guild_members_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "item_modifiers" ADD CONSTRAINT "item_modifiers_item_id_fkey" FOREIGN KEY ("item_id") REFERENCES "items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "item_images" ADD CONSTRAINT "item_images_item_id_fkey" FOREIGN KEY ("item_id") REFERENCES "items"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "chat_messages" ADD CONSTRAINT "chat_messages_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "chat_message_reports" ADD CONSTRAINT "chat_message_reports_message_id_fkey" FOREIGN KEY ("message_id") REFERENCES "chat_messages"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "chat_message_reports" ADD CONSTRAINT "chat_message_reports_reporter_user_id_fkey" FOREIGN KEY ("reporter_user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "tavern_posts" ADD CONSTRAINT "tavern_posts_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "profile_comments" ADD CONSTRAINT "profile_comments_profile_user_id_fkey" FOREIGN KEY ("profile_user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "profile_comments" ADD CONSTRAINT "profile_comments_author_user_id_fkey" FOREIGN KEY ("author_user_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "wiki_pages" ADD CONSTRAINT "wiki_pages_author_id_fkey" FOREIGN KEY ("author_id") REFERENCES "users"("id") ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "wiki_page_categories" ADD CONSTRAINT "wiki_page_categories_page_id_fkey" FOREIGN KEY ("page_id") REFERENCES "wiki_pages"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "wiki_page_categories" ADD CONSTRAINT "wiki_page_categories_category_id_fkey" FOREIGN KEY ("category_id") REFERENCES "wiki_categories"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "world_towers" ADD CONSTRAINT "world_towers_area_id_fkey" FOREIGN KEY ("area_id") REFERENCES "world_areas"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "support_tickets" ADD CONSTRAINT "support_tickets_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "support_replies" ADD CONSTRAINT "support_replies_ticket_id_fkey" FOREIGN KEY ("ticket_id") REFERENCES "support_tickets"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "news" ADD CONSTRAINT "news_author_id_fkey" FOREIGN KEY ("author_id") REFERENCES "users"("id") ON UPDATE CASCADE;
