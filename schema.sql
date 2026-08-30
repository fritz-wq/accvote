CREATE SCHEMA "public";
CREATE SCHEMA "neon_auth";
CREATE TABLE "activity_logs" (
	"id" serial PRIMARY KEY,
	"admin_username" varchar(50),
	"action" text NOT NULL,
	"created_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL
);
CREATE TABLE "admins" (
	"id" serial PRIMARY KEY,
	"username" varchar(50) NOT NULL CONSTRAINT "admins_username_key" UNIQUE,
	"password_hash" varchar(255) NOT NULL,
	"email" varchar(100),
	"role" varchar(20) DEFAULT 'admin',
	"created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
	"last_login" timestamp
);
CREATE TABLE "candidates" (
	"id" serial PRIMARY KEY,
	"position_id" integer NOT NULL,
	"photo" text,
	"course" varchar(100),
	"candidate_year" varchar(20),
	"platform" text,
	"party" varchar(100),
	"name" varchar(100) NOT NULL,
	"department" varchar(50)
);
CREATE TABLE "departments" (
	"id" serial PRIMARY KEY,
	"code" varchar(20) NOT NULL CONSTRAINT "departments_code_key" UNIQUE,
	"name" varchar(100) NOT NULL,
	"sort_order" integer DEFAULT 0 NOT NULL,
	"logo" text
);
CREATE TABLE "election_positions" (
	"id" serial PRIMARY KEY,
	"election_id" integer NOT NULL,
	"position_id" integer NOT NULL,
	"winner_count" integer DEFAULT 1,
	"candidate_limit" integer,
	"year_restriction" varchar(20),
	CONSTRAINT "election_positions_election_id_position_id_key" UNIQUE("election_id","position_id")
);
CREATE TABLE "elections" (
	"id" serial PRIMARY KEY,
	"name" varchar(100) NOT NULL,
	"type" varchar(10) NOT NULL,
	"department" varchar(50),
	"status" varchar(20) DEFAULT 'draft',
	"start_date" timestamp NOT NULL,
	"end_date" timestamp NOT NULL,
	"results_visibility" varchar(20) DEFAULT 'after',
	"parties_enabled" boolean DEFAULT false,
	"created_at" timestamp DEFAULT CURRENT_TIMESTAMP,
	"parties" jsonb DEFAULT '[]',
	CONSTRAINT "elections_results_visibility_check" CHECK (((results_visibility)::text = ANY ((ARRAY['always'::character varying, 'after'::character varying, 'never'::character varying])::text[]))),
	CONSTRAINT "elections_status_check" CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'scheduled'::character varying, 'ongoing'::character varying, 'paused'::character varying, 'closed'::character varying, 'archived'::character varying])::text[]))),
	CONSTRAINT "elections_type_check" CHECK (((type)::text = ANY ((ARRAY['SSG'::character varying, 'DSG'::character varying])::text[])))
);
CREATE TABLE "login_attempts" (
	"id" serial PRIMARY KEY,
	"identifier" varchar(150) NOT NULL,
	"attempted_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL
);
CREATE TABLE "majors" (
	"id" serial PRIMARY KEY,
	"department_code" varchar(20) NOT NULL,
	"name" varchar(100) NOT NULL,
	"sort_order" integer DEFAULT 0 NOT NULL
);
CREATE TABLE "positions" (
	"id" serial PRIMARY KEY,
	"title" varchar(50) NOT NULL
);
CREATE TABLE "site_settings" (
	"setting_key" varchar(50) PRIMARY KEY,
	"setting_value" text,
	"updated_at" timestamp DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE "users" (
	"id" serial PRIMARY KEY,
	"student_id" varchar(20) NOT NULL CONSTRAINT "users_student_id_key" UNIQUE,
	"name" varchar(100) NOT NULL,
	"password" varchar(255),
	"department" varchar(50),
	"year_level" varchar(20),
	"has_voted" integer DEFAULT 0 NOT NULL,
	"registration_status" varchar(20) DEFAULT 'unregistered',
	"academic_year" varchar(10),
	"major" varchar(100),
	"section" varchar(20),
	"email" varchar(100)
);
CREATE TABLE "vote_drafts" (
	"id" serial PRIMARY KEY,
	"user_id" integer NOT NULL,
	"election_id" integer NOT NULL,
	"selections" jsonb DEFAULT '{}' NOT NULL,
	"updated_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
	CONSTRAINT "vote_drafts_user_id_election_id_key" UNIQUE("user_id","election_id")
);
CREATE TABLE "votes" (
	"id" serial PRIMARY KEY,
	"user_id" integer NOT NULL,
	"candidate_id" integer NOT NULL,
	"position_id" integer NOT NULL,
	"voted_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
	CONSTRAINT "votes_user_id_candidate_id_key" UNIQUE("user_id","candidate_id")
);
CREATE TABLE "year_levels" (
	"id" serial PRIMARY KEY,
	"name" varchar(20) NOT NULL CONSTRAINT "year_levels_name_key" UNIQUE,
	"sort_order" integer DEFAULT 0 NOT NULL
);
CREATE TABLE "neon_auth"."account" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"accountId" text NOT NULL,
	"providerId" text NOT NULL,
	"userId" uuid NOT NULL,
	"accessToken" text,
	"refreshToken" text,
	"idToken" text,
	"accessTokenExpiresAt" timestamp with time zone,
	"refreshTokenExpiresAt" timestamp with time zone,
	"scope" text,
	"password" text,
	"createdAt" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"updatedAt" timestamp with time zone NOT NULL
);
CREATE TABLE "neon_auth"."invitation" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"organizationId" uuid NOT NULL,
	"email" text NOT NULL,
	"role" text,
	"status" text NOT NULL,
	"expiresAt" timestamp with time zone NOT NULL,
	"createdAt" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"inviterId" uuid NOT NULL
);
CREATE TABLE "neon_auth"."jwks" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"publicKey" text NOT NULL,
	"privateKey" text NOT NULL,
	"createdAt" timestamp with time zone NOT NULL,
	"expiresAt" timestamp with time zone
);
CREATE TABLE "neon_auth"."member" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"organizationId" uuid NOT NULL,
	"userId" uuid NOT NULL,
	"role" text NOT NULL,
	"createdAt" timestamp with time zone NOT NULL
);
CREATE TABLE "neon_auth"."organization" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"name" text NOT NULL,
	"slug" text NOT NULL CONSTRAINT "organization_slug_key" UNIQUE,
	"logo" text,
	"createdAt" timestamp with time zone NOT NULL,
	"metadata" text
);
CREATE TABLE "neon_auth"."project_config" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"name" text NOT NULL,
	"endpoint_id" text NOT NULL CONSTRAINT "project_config_endpoint_id_key" UNIQUE,
	"created_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"updated_at" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"trusted_origins" jsonb NOT NULL,
	"social_providers" jsonb NOT NULL,
	"email_provider" jsonb,
	"email_and_password" jsonb,
	"allow_localhost" boolean NOT NULL,
	"plugin_configs" jsonb,
	"webhook_config" jsonb
);
CREATE TABLE "neon_auth"."session" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"expiresAt" timestamp with time zone NOT NULL,
	"token" text NOT NULL CONSTRAINT "session_token_key" UNIQUE,
	"createdAt" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"updatedAt" timestamp with time zone NOT NULL,
	"ipAddress" text,
	"userAgent" text,
	"userId" uuid NOT NULL,
	"impersonatedBy" text,
	"activeOrganizationId" text
);
CREATE TABLE "neon_auth"."user" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"name" text NOT NULL,
	"email" text NOT NULL CONSTRAINT "user_email_key" UNIQUE,
	"emailVerified" boolean NOT NULL,
	"image" text,
	"createdAt" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"updatedAt" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"role" text,
	"banned" boolean,
	"banReason" text,
	"banExpires" timestamp with time zone
);
CREATE TABLE "neon_auth"."verification" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
	"identifier" text NOT NULL,
	"value" text NOT NULL,
	"expiresAt" timestamp with time zone NOT NULL,
	"createdAt" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
	"updatedAt" timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);
CREATE UNIQUE INDEX "activity_logs_pkey" ON "activity_logs" ("id");
CREATE INDEX "idx_activity_logs_created" ON "activity_logs" ("created_at");
CREATE UNIQUE INDEX "admins_pkey" ON "admins" ("id");
CREATE UNIQUE INDEX "admins_username_key" ON "admins" ("username");
CREATE UNIQUE INDEX "candidates_pkey" ON "candidates" ("id");
CREATE INDEX "idx_candidates_position" ON "candidates" ("position_id");
CREATE UNIQUE INDEX "departments_code_key" ON "departments" ("code");
CREATE UNIQUE INDEX "departments_pkey" ON "departments" ("id");
CREATE UNIQUE INDEX "election_positions_election_id_position_id_key" ON "election_positions" ("election_id","position_id");
CREATE UNIQUE INDEX "election_positions_pkey" ON "election_positions" ("id");
CREATE INDEX "idx_election_positions_position" ON "election_positions" ("position_id");
CREATE UNIQUE INDEX "elections_pkey" ON "elections" ("id");
CREATE INDEX "idx_login_attempts_identifier_time" ON "login_attempts" ("identifier","attempted_at");
CREATE UNIQUE INDEX "login_attempts_pkey" ON "login_attempts" ("id");
CREATE INDEX "idx_majors_department" ON "majors" ("department_code");
CREATE UNIQUE INDEX "majors_pkey" ON "majors" ("id");
CREATE UNIQUE INDEX "positions_pkey" ON "positions" ("id");
CREATE UNIQUE INDEX "site_settings_pkey" ON "site_settings" ("setting_key");
CREATE UNIQUE INDEX "users_pkey" ON "users" ("id");
CREATE UNIQUE INDEX "users_student_id_key" ON "users" ("student_id");
CREATE UNIQUE INDEX "vote_drafts_pkey" ON "vote_drafts" ("id");
CREATE UNIQUE INDEX "vote_drafts_user_id_election_id_key" ON "vote_drafts" ("user_id","election_id");
CREATE INDEX "idx_votes_candidate" ON "votes" ("candidate_id");
CREATE UNIQUE INDEX "votes_pkey" ON "votes" ("id");
CREATE UNIQUE INDEX "votes_user_id_candidate_id_key" ON "votes" ("user_id","candidate_id");
CREATE UNIQUE INDEX "year_levels_name_key" ON "year_levels" ("name");
CREATE UNIQUE INDEX "year_levels_pkey" ON "year_levels" ("id");
CREATE UNIQUE INDEX "account_pkey" ON "neon_auth"."account" ("id");
CREATE INDEX "account_userId_idx" ON "neon_auth"."account" ("userId");
CREATE INDEX "invitation_email_idx" ON "neon_auth"."invitation" ("email");
CREATE INDEX "invitation_organizationId_idx" ON "neon_auth"."invitation" ("organizationId");
CREATE UNIQUE INDEX "invitation_pkey" ON "neon_auth"."invitation" ("id");
CREATE UNIQUE INDEX "jwks_pkey" ON "neon_auth"."jwks" ("id");
CREATE INDEX "member_organizationId_idx" ON "neon_auth"."member" ("organizationId");
CREATE UNIQUE INDEX "member_pkey" ON "neon_auth"."member" ("id");
CREATE INDEX "member_userId_idx" ON "neon_auth"."member" ("userId");
CREATE UNIQUE INDEX "organization_pkey" ON "neon_auth"."organization" ("id");
CREATE UNIQUE INDEX "organization_slug_key" ON "neon_auth"."organization" ("slug");
CREATE UNIQUE INDEX "organization_slug_uidx" ON "neon_auth"."organization" ("slug");
CREATE UNIQUE INDEX "project_config_endpoint_id_key" ON "neon_auth"."project_config" ("endpoint_id");
CREATE UNIQUE INDEX "project_config_pkey" ON "neon_auth"."project_config" ("id");
CREATE UNIQUE INDEX "session_pkey" ON "neon_auth"."session" ("id");
CREATE UNIQUE INDEX "session_token_key" ON "neon_auth"."session" ("token");
CREATE INDEX "session_userId_idx" ON "neon_auth"."session" ("userId");
CREATE UNIQUE INDEX "user_email_key" ON "neon_auth"."user" ("email");
CREATE UNIQUE INDEX "user_pkey" ON "neon_auth"."user" ("id");
CREATE INDEX "verification_identifier_idx" ON "neon_auth"."verification" ("identifier");
CREATE UNIQUE INDEX "verification_pkey" ON "neon_auth"."verification" ("id");
ALTER TABLE "candidates" ADD CONSTRAINT "candidates_department_fkey" FOREIGN KEY ("department") REFERENCES "departments"("code") ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "candidates" ADD CONSTRAINT "candidates_position_id_fkey" FOREIGN KEY ("position_id") REFERENCES "positions"("id") ON DELETE CASCADE;
ALTER TABLE "election_positions" ADD CONSTRAINT "election_positions_election_id_fkey" FOREIGN KEY ("election_id") REFERENCES "elections"("id") ON DELETE CASCADE;
ALTER TABLE "election_positions" ADD CONSTRAINT "election_positions_position_id_fkey" FOREIGN KEY ("position_id") REFERENCES "positions"("id") ON DELETE CASCADE;
ALTER TABLE "majors" ADD CONSTRAINT "majors_department_code_fkey" FOREIGN KEY ("department_code") REFERENCES "departments"("code") ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE "vote_drafts" ADD CONSTRAINT "vote_drafts_election_id_fkey" FOREIGN KEY ("election_id") REFERENCES "elections"("id") ON DELETE CASCADE;
ALTER TABLE "vote_drafts" ADD CONSTRAINT "vote_drafts_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE;
ALTER TABLE "votes" ADD CONSTRAINT "votes_candidate_id_fkey" FOREIGN KEY ("candidate_id") REFERENCES "candidates"("id") ON DELETE CASCADE;
ALTER TABLE "votes" ADD CONSTRAINT "votes_position_id_fkey" FOREIGN KEY ("position_id") REFERENCES "positions"("id") ON DELETE CASCADE;
ALTER TABLE "votes" ADD CONSTRAINT "votes_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "users"("id") ON DELETE CASCADE;
ALTER TABLE "neon_auth"."account" ADD CONSTRAINT "account_userId_fkey" FOREIGN KEY ("userId") REFERENCES "neon_auth"."user"("id") ON DELETE CASCADE;
ALTER TABLE "neon_auth"."invitation" ADD CONSTRAINT "invitation_inviterId_fkey" FOREIGN KEY ("inviterId") REFERENCES "neon_auth"."user"("id") ON DELETE CASCADE;
ALTER TABLE "neon_auth"."invitation" ADD CONSTRAINT "invitation_organizationId_fkey" FOREIGN KEY ("organizationId") REFERENCES "neon_auth"."organization"("id") ON DELETE CASCADE;
ALTER TABLE "neon_auth"."member" ADD CONSTRAINT "member_organizationId_fkey" FOREIGN KEY ("organizationId") REFERENCES "neon_auth"."organization"("id") ON DELETE CASCADE;
ALTER TABLE "neon_auth"."member" ADD CONSTRAINT "member_userId_fkey" FOREIGN KEY ("userId") REFERENCES "neon_auth"."user"("id") ON DELETE CASCADE;
ALTER TABLE "neon_auth"."session" ADD CONSTRAINT "session_userId_fkey" FOREIGN KEY ("userId") REFERENCES "neon_auth"."user"("id") ON DELETE CASCADE;
