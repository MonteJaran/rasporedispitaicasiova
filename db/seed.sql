-- =============================================================================
--  seed.sql — kompletna šema + test podaci
--  Projekat: Raspored ispita i časova (FIT)
--
--  Sadrži:
--    - 14 tabela sa indeksima, stranim ključevima i triggerom
--    - 17 profesora, 12 sala, 30 predmeta (10 po godini / 5 po semestru)
--    - svaki predmet ima nosioca i zamjenika; profesori drže više predmeta
--    - 2 akademske godine, 28 praznika, 40 termina raspoloživosti,
--      44 reda zauzetosti sala, 32 bilješke, 18 naloga, 3 config ključa
--    - 200 događaja: predavanja, vježbe, labovi, kolokvijumi i ispiti
--
--  UPOZORENJE: skripta prvo BRIŠE ovih 14 tabela (DROP ... CASCADE).
--              Sve je u jednoj transakciji — ili prođe sve ili ništa.
--
--  Pokretanje:
--    psql "<connection-string>" -f db/seed.sql
--    ili nalijepi cijeli sadržaj u Supabase SQL Editor i pokreni.
--
--  Prijava nakon punjenja:
--    admin / admin123                 (prijava po korisničkom imenu)
--    <email profesora> / lozinka123   (npr. ana.popovic@fit.edu.me)
-- =============================================================================

SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;
SET search_path = public;

BEGIN;

-- --------------------------------------------------------------- BRISANJE ---
DROP TRIGGER IF EXISTS trg_academic_event_sync_date ON public.academic_event;

DROP TABLE IF EXISTS public.room_occupancy         CASCADE;
DROP TABLE IF EXISTS public.event_professor        CASCADE;
DROP TABLE IF EXISTS public.notes                  CASCADE;
DROP TABLE IF EXISTS public.professor_availability CASCADE;
DROP TABLE IF EXISTS public.academic_event         CASCADE;
DROP TABLE IF EXISTS public.course_professor       CASCADE;
DROP TABLE IF EXISTS public.user_account           CASCADE;
DROP TABLE IF EXISTS public.course                 CASCADE;
DROP TABLE IF EXISTS public.room                   CASCADE;
DROP TABLE IF EXISTS public.professor              CASCADE;
DROP TABLE IF EXISTS public.academic_year          CASCADE;
DROP TABLE IF EXISTS public.holiday                CASCADE;
DROP TABLE IF EXISTS public.config                 CASCADE;
DROP TABLE IF EXISTS public.schedule               CASCADE;

DROP FUNCTION IF EXISTS public.academic_event_sync_date() CASCADE;

-- ------------------------------------------------------- ŠEMA I PODACI -----

-- Name: academic_event_sync_date(); Type: FUNCTION; Schema: public; Owner: -

CREATE FUNCTION public.academic_event_sync_date() RETURNS trigger
    LANGUAGE plpgsql
    AS $$ BEGIN NEW.date := NEW.starts_at::date; RETURN NEW; END; $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

-- Name: academic_event; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.academic_event (
    id bigint NOT NULL,
    course_id bigint,
    created_by_professor bigint,
    type_enum character varying(30) NOT NULL,
    starts_at timestamp without time zone NOT NULL,
    ends_at timestamp without time zone NOT NULL,
    date date,
    room_id bigint,
    is_online boolean DEFAULT false NOT NULL,
    notes text,
    is_published boolean DEFAULT true NOT NULL,
    locked_by_admin boolean DEFAULT false NOT NULL,
    schedule_id integer,
    day character varying(20)
);


-- Name: academic_event_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.academic_event_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: academic_event_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.academic_event_id_seq OWNED BY public.academic_event.id;


-- Name: academic_year; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.academic_year (
    id bigint NOT NULL,
    year_label character varying(9) NOT NULL,
    winter_semester_start date NOT NULL,
    summer_semester_start date NOT NULL,
    is_active boolean DEFAULT true
);


-- Name: academic_year_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.academic_year_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: academic_year_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.academic_year_id_seq OWNED BY public.academic_year.id;


-- Name: config; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.config (
    key character varying(100) NOT NULL,
    value text
);


-- Name: course; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.course (
    id bigint NOT NULL,
    name character varying(200) NOT NULL,
    code character varying(50),
    semester smallint NOT NULL,
    is_optional boolean DEFAULT false NOT NULL,
    lectures_per_week integer DEFAULT 2 NOT NULL,
    exercises_per_week integer DEFAULT 2 NOT NULL,
    labs_per_week integer DEFAULT 0 NOT NULL,
    is_online boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    major character varying(100),
    colloquium_1_week integer,
    colloquium_2_week integer,
    final_exam_week integer,
    requires_computer_lab boolean DEFAULT false,
    expected_students integer,
    parallel_groups integer DEFAULT 1,
    CONSTRAINT course_semester_chk CHECK (((semester >= 1) AND (semester <= 6)))
);


-- Name: course_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.course_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: course_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.course_id_seq OWNED BY public.course.id;


-- Name: course_professor; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.course_professor (
    id bigint NOT NULL,
    course_id bigint NOT NULL,
    professor_id bigint NOT NULL,
    is_assistant boolean DEFAULT false NOT NULL
);


-- Name: course_professor_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.course_professor_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: course_professor_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.course_professor_id_seq OWNED BY public.course_professor.id;


-- Name: event_professor; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.event_professor (
    id bigint NOT NULL,
    event_id bigint NOT NULL,
    professor_id bigint NOT NULL
);


-- Name: event_professor_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.event_professor_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: event_professor_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.event_professor_id_seq OWNED BY public.event_professor.id;


-- Name: holiday; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.holiday (
    id integer NOT NULL,
    date date NOT NULL,
    name text NOT NULL,
    is_working_day integer DEFAULT 1 NOT NULL
);


-- Name: holiday_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.holiday_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: holiday_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.holiday_id_seq OWNED BY public.holiday.id;


-- Name: notes; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.notes (
    id bigint NOT NULL,
    professor_id bigint NOT NULL,
    note_date date NOT NULL,
    content text
);


-- Name: notes_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.notes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: notes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.notes_id_seq OWNED BY public.notes.id;


-- Name: professor; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.professor (
    id bigint NOT NULL,
    full_name character varying(150) NOT NULL,
    email character varying(150),
    is_active boolean DEFAULT true NOT NULL
);


-- Name: professor_availability; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.professor_availability (
    id bigint NOT NULL,
    professor_id bigint NOT NULL,
    weekday smallint NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    requires_computer_lab boolean DEFAULT false,
    preferred_room_id bigint,
    course_id bigint,
    CONSTRAINT professor_availability_weekday_chk CHECK (((weekday >= 1) AND (weekday <= 7)))
);


-- Name: professor_availability_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.professor_availability_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: professor_availability_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.professor_availability_id_seq OWNED BY public.professor_availability.id;


-- Name: professor_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.professor_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: professor_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.professor_id_seq OWNED BY public.professor.id;


-- Name: room; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.room (
    id bigint NOT NULL,
    code character varying(50) NOT NULL,
    capacity integer DEFAULT 30 NOT NULL,
    is_computer_lab boolean DEFAULT false NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    faculty_code character varying(20)
);


-- Name: room_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.room_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: room_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.room_id_seq OWNED BY public.room.id;


-- Name: room_occupancy; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.room_occupancy (
    id bigint NOT NULL,
    room_id bigint NOT NULL,
    weekday smallint NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    faculty_code character varying(20) NOT NULL,
    source_type character varying(20) DEFAULT 'MANUAL'::character varying,
    academic_year_id bigint NOT NULL,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


-- Name: room_occupancy_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.room_occupancy_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: room_occupancy_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.room_occupancy_id_seq OWNED BY public.room_occupancy.id;


-- Name: schedule; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.schedule (
    id integer NOT NULL,
    name character varying(150)
);


-- Name: user_account; Type: TABLE; Schema: public; Owner: -

CREATE TABLE public.user_account (
    id bigint NOT NULL,
    username character varying(150) NOT NULL,
    password_hash character varying(255) NOT NULL,
    role_enum character varying(20) DEFAULT 'PROFESSOR'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    professor_id bigint,
    CONSTRAINT user_account_role_chk CHECK (((role_enum)::text = ANY ((ARRAY['ADMIN'::character varying, 'PROFESSOR'::character varying])::text[])))
);


-- Name: user_account_id_seq; Type: SEQUENCE; Schema: public; Owner: -

CREATE SEQUENCE public.user_account_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


-- Name: user_account_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -

ALTER SEQUENCE public.user_account_id_seq OWNED BY public.user_account.id;


-- Name: academic_event id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.academic_event ALTER COLUMN id SET DEFAULT nextval('public.academic_event_id_seq'::regclass);


-- Name: academic_year id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.academic_year ALTER COLUMN id SET DEFAULT nextval('public.academic_year_id_seq'::regclass);


-- Name: course id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.course ALTER COLUMN id SET DEFAULT nextval('public.course_id_seq'::regclass);


-- Name: course_professor id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.course_professor ALTER COLUMN id SET DEFAULT nextval('public.course_professor_id_seq'::regclass);


-- Name: event_professor id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.event_professor ALTER COLUMN id SET DEFAULT nextval('public.event_professor_id_seq'::regclass);


-- Name: holiday id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.holiday ALTER COLUMN id SET DEFAULT nextval('public.holiday_id_seq'::regclass);


-- Name: notes id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.notes ALTER COLUMN id SET DEFAULT nextval('public.notes_id_seq'::regclass);


-- Name: professor id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.professor ALTER COLUMN id SET DEFAULT nextval('public.professor_id_seq'::regclass);


-- Name: professor_availability id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.professor_availability ALTER COLUMN id SET DEFAULT nextval('public.professor_availability_id_seq'::regclass);


-- Name: room id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.room ALTER COLUMN id SET DEFAULT nextval('public.room_id_seq'::regclass);


-- Name: room_occupancy id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.room_occupancy ALTER COLUMN id SET DEFAULT nextval('public.room_occupancy_id_seq'::regclass);


-- Name: user_account id; Type: DEFAULT; Schema: public; Owner: -

ALTER TABLE ONLY public.user_account ALTER COLUMN id SET DEFAULT nextval('public.user_account_id_seq'::regclass);


-- Data for Name: academic_event; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.academic_event VALUES
	(1, 1, 1, 'LECTURE', '2026-10-05 08:00:00', '2026-10-05 11:00:00', '2026-10-05', 1, false, NULL, true, false, 1, 'ponedeljak'),
	(2, 1, 13, 'EXERCISE', '2026-10-05 11:00:00', '2026-10-05 13:00:00', '2026-10-05', 1, false, NULL, true, false, 1, 'ponedeljak'),
	(3, 2, 2, 'LECTURE', '2026-10-07 08:00:00', '2026-10-07 11:00:00', '2026-10-07', 1, false, NULL, true, false, 1, 'srijeda'),
	(4, 2, 14, 'EXERCISE', '2026-10-07 11:00:00', '2026-10-07 13:00:00', '2026-10-07', 1, false, NULL, true, false, 1, 'srijeda'),
	(5, 2, 14, 'LAB', '2026-10-07 13:00:00', '2026-10-07 14:00:00', '2026-10-07', 7, false, NULL, true, false, 1, 'srijeda'),
	(6, 3, 10, 'LECTURE', '2026-10-09 08:00:00', '2026-10-09 11:00:00', '2026-10-09', 1, false, NULL, true, false, 1, 'petak'),
	(7, 3, 12, 'EXERCISE', '2026-10-09 11:00:00', '2026-10-09 13:00:00', '2026-10-09', 1, false, NULL, true, false, 1, 'petak'),
	(8, 3, 12, 'LAB', '2026-10-09 13:00:00', '2026-10-09 14:00:00', '2026-10-09', 7, false, NULL, true, false, 1, 'petak'),
	(9, 4, 11, 'LECTURE', '2026-10-06 08:00:00', '2026-10-06 11:00:00', '2026-10-06', 1, false, NULL, true, false, 1, 'utorak'),
	(10, 4, 15, 'EXERCISE', '2026-10-06 11:00:00', '2026-10-06 13:00:00', '2026-10-06', 1, false, NULL, true, false, 1, 'utorak'),
	(11, 4, 15, 'LAB', '2026-10-06 13:00:00', '2026-10-06 14:00:00', '2026-10-06', 7, false, NULL, true, false, 1, 'utorak'),
	(12, 5, 6, 'LECTURE', '2026-10-08 08:00:00', '2026-10-08 10:00:00', '2026-10-08', 1, false, NULL, true, false, 1, 'cetvrtak'),
	(13, 5, 16, 'EXERCISE', '2026-10-08 10:00:00', '2026-10-08 12:00:00', '2026-10-08', 1, false, NULL, true, false, 1, 'cetvrtak'),
	(14, 6, 9, 'LECTURE', '2027-02-15 08:00:00', '2027-02-15 11:00:00', '2027-02-15', 2, false, NULL, true, false, 2, 'ponedeljak'),
	(15, 6, 13, 'EXERCISE', '2027-02-15 13:00:00', '2027-02-15 14:00:00', '2027-02-15', 1, false, NULL, true, false, 2, 'ponedeljak'),
	(16, 6, 13, 'LAB', '2027-02-15 14:00:00', '2027-02-15 15:00:00', '2027-02-15', 7, false, NULL, true, false, 2, 'ponedeljak'),
	(17, 7, 2, 'LECTURE', '2027-02-17 11:00:00', '2027-02-17 14:00:00', '2027-02-17', 2, false, NULL, true, false, 2, 'srijeda'),
	(18, 7, 14, 'EXERCISE', '2027-02-17 08:00:00', '2027-02-17 10:00:00', '2027-02-17', 2, false, NULL, true, false, 2, 'srijeda'),
	(19, 7, 14, 'LAB', '2027-02-17 10:00:00', '2027-02-17 11:00:00', '2027-02-17', 7, false, NULL, true, false, 2, 'srijeda'),
	(20, 8, 5, 'LECTURE', '2027-02-19 08:00:00', '2027-02-19 11:00:00', '2027-02-19', 2, false, NULL, true, false, 2, 'petak'),
	(21, 8, 16, 'EXERCISE', '2027-02-19 11:00:00', '2027-02-19 13:00:00', '2027-02-19', 2, false, NULL, true, false, 2, 'petak'),
	(22, 8, 16, 'LAB', '2027-02-19 13:00:00', '2027-02-19 14:00:00', '2027-02-19', 8, false, NULL, true, false, 2, 'petak'),
	(23, 9, 3, 'LECTURE', '2027-02-16 08:00:00', '2027-02-16 11:00:00', '2027-02-16', 2, false, NULL, true, false, 2, 'utorak'),
	(24, 9, 15, 'EXERCISE', '2027-02-16 14:00:00', '2027-02-16 15:00:00', '2027-02-16', 1, false, NULL, true, false, 2, 'utorak'),
	(25, 9, 15, 'LAB', '2027-02-16 15:00:00', '2027-02-16 16:00:00', '2027-02-16', 7, false, NULL, true, false, 2, 'utorak'),
	(26, 10, 6, 'LECTURE', '2027-02-18 10:00:00', '2027-02-18 12:00:00', '2027-02-18', 2, false, NULL, true, false, 2, 'cetvrtak'),
	(27, 10, 12, 'EXERCISE', '2027-02-18 08:00:00', '2027-02-18 10:00:00', '2027-02-18', 2, false, NULL, true, false, 2, 'cetvrtak'),
	(28, 11, 4, 'LECTURE', '2026-10-05 08:00:00', '2026-10-05 11:00:00', '2026-10-05', 3, false, NULL, true, false, 1, 'ponedeljak'),
	(29, 11, 14, 'EXERCISE', '2026-10-05 11:00:00', '2026-10-05 13:00:00', '2026-10-05', 2, false, NULL, true, false, 1, 'ponedeljak'),
	(30, 11, 14, 'LAB', '2026-10-05 13:00:00', '2026-10-05 14:00:00', '2026-10-05', 7, false, NULL, true, false, 1, 'ponedeljak'),
	(31, 12, 1, 'LECTURE', '2026-10-07 08:00:00', '2026-10-07 11:00:00', '2026-10-07', 3, false, NULL, true, false, 1, 'srijeda'),
	(32, 12, 13, 'EXERCISE', '2026-10-07 11:00:00', '2026-10-07 13:00:00', '2026-10-07', 3, false, NULL, true, false, 1, 'srijeda'),
	(33, 12, 13, 'LAB', '2026-10-07 13:00:00', '2026-10-07 14:00:00', '2026-10-07', 8, false, NULL, true, false, 1, 'srijeda'),
	(34, 13, 8, 'LECTURE', '2026-10-09 08:00:00', '2026-10-09 11:00:00', '2026-10-09', 3, false, NULL, true, false, 1, 'petak'),
	(35, 13, 12, 'EXERCISE', '2026-10-09 14:00:00', '2026-10-09 15:00:00', '2026-10-09', 1, false, NULL, true, false, 1, 'petak'),
	(36, 14, 3, 'LECTURE', '2026-10-06 11:00:00', '2026-10-06 14:00:00', '2026-10-06', 2, false, NULL, true, false, 1, 'utorak'),
	(37, 14, 15, 'EXERCISE', '2026-10-06 08:00:00', '2026-10-06 10:00:00', '2026-10-06', 3, false, NULL, true, false, 1, 'utorak'),
	(38, 14, 15, 'LAB', '2026-10-06 10:00:00', '2026-10-06 11:00:00', '2026-10-06', 7, false, NULL, true, false, 1, 'utorak'),
	(39, 15, 6, 'LECTURE', '2026-10-08 12:00:00', '2026-10-08 14:00:00', '2026-10-08', 1, false, NULL, true, false, 1, 'cetvrtak'),
	(40, 15, 16, 'EXERCISE', '2026-10-08 08:00:00', '2026-10-08 10:00:00', '2026-10-08', 3, false, NULL, true, false, 1, 'cetvrtak');
INSERT INTO public.academic_event VALUES
	(41, 16, 9, 'LECTURE', '2027-02-15 11:00:00', '2027-02-15 13:00:00', '2027-02-15', 3, false, NULL, true, false, 2, 'ponedeljak'),
	(42, 16, 13, 'EXERCISE', '2027-02-15 08:00:00', '2027-02-15 10:00:00', '2027-02-15', 4, false, NULL, true, false, 2, 'ponedeljak'),
	(43, 16, 13, 'LAB', '2027-02-15 10:00:00', '2027-02-15 11:00:00', '2027-02-15', 7, false, NULL, true, false, 2, 'ponedeljak'),
	(44, 17, 1, 'LECTURE', '2027-02-17 11:00:00', '2027-02-17 14:00:00', '2027-02-17', 4, false, NULL, true, false, 2, 'srijeda'),
	(45, 17, 12, 'EXERCISE', '2027-02-17 08:00:00', '2027-02-17 10:00:00', '2027-02-17', 4, false, NULL, true, false, 2, 'srijeda'),
	(46, 18, 6, 'LECTURE', '2027-02-19 08:00:00', '2027-02-19 10:00:00', '2027-02-19', 4, true, NULL, true, false, 2, 'petak'),
	(47, 18, 16, 'EXERCISE', '2027-02-19 14:00:00', '2027-02-19 16:00:00', '2027-02-19', 2, true, NULL, true, false, 2, 'petak'),
	(48, 19, 2, 'LECTURE', '2027-02-16 08:00:00', '2027-02-16 11:00:00', '2027-02-16', 4, false, NULL, true, false, 2, 'utorak'),
	(49, 19, 14, 'EXERCISE', '2027-02-16 11:00:00', '2027-02-16 13:00:00', '2027-02-16', 3, false, NULL, true, false, 2, 'utorak'),
	(50, 19, 14, 'LAB', '2027-02-16 13:00:00', '2027-02-16 14:00:00', '2027-02-16', 8, false, NULL, true, false, 2, 'utorak'),
	(51, 20, 3, 'LECTURE', '2027-02-18 08:00:00', '2027-02-18 11:00:00', '2027-02-18', 4, false, NULL, true, false, 2, 'cetvrtak'),
	(52, 20, 15, 'EXERCISE', '2027-02-18 11:00:00', '2027-02-18 13:00:00', '2027-02-18', 3, false, NULL, true, false, 2, 'cetvrtak'),
	(53, 20, 15, 'LAB', '2027-02-18 13:00:00', '2027-02-18 14:00:00', '2027-02-18', 7, false, NULL, true, false, 2, 'cetvrtak'),
	(54, 21, 7, 'LECTURE', '2026-10-05 08:00:00', '2026-10-05 11:00:00', '2026-10-05', 5, false, NULL, true, false, 1, 'ponedeljak'),
	(55, 21, 15, 'EXERCISE', '2026-10-05 11:00:00', '2026-10-05 13:00:00', '2026-10-05', 4, false, NULL, true, false, 1, 'ponedeljak'),
	(56, 21, 15, 'LAB', '2026-10-05 13:00:00', '2026-10-05 14:00:00', '2026-10-05', 8, false, NULL, true, false, 1, 'ponedeljak'),
	(57, 22, 1, 'LECTURE', '2026-10-07 14:00:00', '2026-10-07 17:00:00', '2026-10-07', 1, false, NULL, true, false, 1, 'srijeda'),
	(58, 22, 13, 'EXERCISE', '2026-10-07 08:00:00', '2026-10-07 10:00:00', '2026-10-07', 5, false, NULL, true, false, 1, 'srijeda'),
	(59, 23, 2, 'LECTURE', '2026-10-09 08:00:00', '2026-10-09 11:00:00', '2026-10-09', 5, false, NULL, true, false, 1, 'petak'),
	(60, 23, 14, 'EXERCISE', '2026-10-09 11:00:00', '2026-10-09 13:00:00', '2026-10-09', 3, false, NULL, true, false, 1, 'petak'),
	(61, 23, 14, 'LAB', '2026-10-09 13:00:00', '2026-10-09 14:00:00', '2026-10-09', 9, false, NULL, true, false, 1, 'petak'),
	(62, 24, 4, 'LECTURE', '2026-10-06 08:00:00', '2026-10-06 11:00:00', '2026-10-06', 5, false, NULL, true, false, 1, 'utorak'),
	(63, 24, 16, 'EXERCISE', '2026-10-06 11:00:00', '2026-10-06 13:00:00', '2026-10-06', 4, false, NULL, true, false, 1, 'utorak'),
	(64, 24, 16, 'LAB', '2026-10-06 13:00:00', '2026-10-06 14:00:00', '2026-10-06', 9, false, NULL, true, false, 1, 'utorak'),
	(65, 25, 3, 'LECTURE', '2026-10-08 11:00:00', '2026-10-08 14:00:00', '2026-10-08', 4, false, NULL, true, false, 1, 'cetvrtak'),
	(66, 25, 12, 'EXERCISE', '2026-10-08 14:00:00', '2026-10-08 16:00:00', '2026-10-08', 1, false, NULL, true, false, 1, 'cetvrtak'),
	(67, 25, 12, 'LAB', '2026-10-08 10:00:00', '2026-10-08 11:00:00', '2026-10-08', 7, false, NULL, true, false, 1, 'cetvrtak'),
	(68, 26, 8, 'LECTURE', '2027-02-15 08:00:00', '2027-02-15 10:00:00', '2027-02-15', 6, false, NULL, true, false, 2, 'ponedeljak'),
	(69, 26, 12, 'EXERCISE', '2027-02-15 10:00:00', '2027-02-15 11:00:00', '2027-02-15', 4, false, NULL, true, false, 2, 'ponedeljak'),
	(70, 26, 12, 'LAB', '2027-02-15 11:00:00', '2027-02-15 12:00:00', '2027-02-15', 7, false, NULL, true, false, 2, 'ponedeljak'),
	(71, 27, 13, 'LAB', '2027-02-17 14:00:00', '2027-02-17 16:00:00', '2027-02-17', 7, false, NULL, true, false, 2, 'srijeda'),
	(72, 28, 11, 'LECTURE', '2027-02-19 08:00:00', '2027-02-19 11:00:00', '2027-02-19', 6, true, NULL, true, false, 2, 'petak'),
	(73, 28, 14, 'EXERCISE', '2027-02-19 14:00:00', '2027-02-19 15:00:00', '2027-02-19', 3, true, NULL, true, false, 2, 'petak'),
	(74, 28, 14, 'LAB', '2027-02-19 15:00:00', '2027-02-19 16:00:00', '2027-02-19', 7, true, NULL, true, false, 2, 'petak'),
	(75, 29, 5, 'LECTURE', '2027-02-16 08:00:00', '2027-02-16 11:00:00', '2027-02-16', 6, false, NULL, true, false, 2, 'utorak'),
	(76, 29, 16, 'EXERCISE', '2027-02-16 14:00:00', '2027-02-16 16:00:00', '2027-02-16', 2, false, NULL, true, false, 2, 'utorak'),
	(77, 29, 16, 'LAB', '2027-02-16 16:00:00', '2027-02-16 17:00:00', '2027-02-16', 7, false, NULL, true, false, 2, 'utorak'),
	(78, 30, 7, 'LECTURE', '2027-02-18 08:00:00', '2027-02-18 11:00:00', '2027-02-18', 5, false, NULL, true, false, 2, 'cetvrtak'),
	(79, 30, 15, 'EXERCISE', '2027-02-18 14:00:00', '2027-02-18 16:00:00', '2027-02-18', 2, false, NULL, true, false, 2, 'cetvrtak'),
	(80, 30, 15, 'LAB', '2027-02-18 16:00:00', '2027-02-18 17:00:00', '2027-02-18', 7, false, NULL, true, false, 2, 'cetvrtak');
INSERT INTO public.academic_event VALUES
	(81, 1, 1, 'COLLOQUIUM_1', '2026-11-02 11:00:00', '2026-11-02 13:00:00', '2026-11-02', 1, false, 'Kolokvijum 1 - Inžinjerska matematika', true, false, 1, 'ponedeljak'),
	(82, 1, 1, 'COLLOQUIUM_2', '2026-12-14 11:00:00', '2026-12-14 13:00:00', '2026-12-14', 1, false, 'Kolokvijum 2 - Inžinjerska matematika', true, false, 1, 'ponedeljak'),
	(83, 2, 2, 'COLLOQUIUM_1', '2026-11-04 11:00:00', '2026-11-04 13:00:00', '2026-11-04', 1, false, 'Kolokvijum 1 - Osnove programiranja', true, false, 1, 'srijeda'),
	(84, 2, 2, 'COLLOQUIUM_2', '2026-12-16 11:00:00', '2026-12-16 13:00:00', '2026-12-16', 1, false, 'Kolokvijum 2 - Osnove programiranja', true, false, 1, 'srijeda'),
	(85, 3, 10, 'COLLOQUIUM_1', '2026-11-16 11:00:00', '2026-11-16 13:00:00', '2026-11-16', 1, false, 'Kolokvijum 1 - CAD projektovanje', true, false, 1, 'ponedeljak'),
	(86, 3, 10, 'COLLOQUIUM_2', '2026-12-25 11:00:00', '2026-12-25 13:00:00', '2026-12-25', 1, false, 'Kolokvijum 2 - CAD projektovanje', true, false, 1, 'petak'),
	(87, 4, 11, 'COLLOQUIUM_1', '2026-11-10 11:00:00', '2026-11-10 13:00:00', '2026-11-10', 1, false, 'Kolokvijum 1 - Informacione tehnologije', true, false, 1, 'utorak'),
	(88, 4, 11, 'COLLOQUIUM_2', '2026-12-22 11:00:00', '2026-12-22 13:00:00', '2026-12-22', 1, false, 'Kolokvijum 2 - Informacione tehnologije', true, false, 1, 'utorak'),
	(89, 5, 6, 'COLLOQUIUM_1', '2026-11-19 10:00:00', '2026-11-19 12:00:00', '2026-11-19', 1, false, 'Kolokvijum 1 - Engleski jezik 1', true, false, 1, 'cetvrtak'),
	(90, 5, 6, 'COLLOQUIUM_2', '2026-12-31 10:00:00', '2026-12-31 12:00:00', '2026-12-31', 1, false, 'Kolokvijum 2 - Engleski jezik 1', true, false, 1, 'cetvrtak'),
	(91, 6, 9, 'COLLOQUIUM_1', '2027-03-15 13:00:00', '2027-03-15 15:00:00', '2027-03-15', 1, false, 'Kolokvijum 1 - Arhitektura računarskih sistema', true, false, 2, 'ponedeljak'),
	(92, 6, 9, 'COLLOQUIUM_2', '2027-04-26 13:00:00', '2027-04-26 15:00:00', '2027-04-26', 1, false, 'Kolokvijum 2 - Arhitektura računarskih sistema', true, false, 2, 'ponedeljak'),
	(93, 7, 2, 'COLLOQUIUM_1', '2027-03-17 08:00:00', '2027-03-17 10:00:00', '2027-03-17', 2, false, 'Kolokvijum 1 - Objektno programiranje 1', true, false, 2, 'srijeda'),
	(94, 7, 2, 'COLLOQUIUM_2', '2027-04-28 08:00:00', '2027-04-28 10:00:00', '2027-04-28', 2, false, 'Kolokvijum 2 - Objektno programiranje 1', true, false, 2, 'srijeda'),
	(95, 8, 5, 'COLLOQUIUM_1', '2027-03-26 11:00:00', '2027-03-26 13:00:00', '2027-03-26', 2, false, 'Kolokvijum 1 - Uvod u web programiranje', true, false, 2, 'petak'),
	(96, 8, 5, 'COLLOQUIUM_2', '2027-05-07 11:00:00', '2027-05-07 13:00:00', '2027-05-07', 2, false, 'Kolokvijum 2 - Uvod u web programiranje', true, false, 2, 'petak'),
	(97, 9, 3, 'COLLOQUIUM_1', '2027-03-23 14:00:00', '2027-03-23 16:00:00', '2027-03-23', 1, false, 'Kolokvijum 1 - Mrežne informacione tehnologije', true, false, 2, 'utorak'),
	(98, 9, 3, 'COLLOQUIUM_2', '2027-05-04 14:00:00', '2027-05-04 16:00:00', '2027-05-04', 1, false, 'Kolokvijum 2 - Mrežne informacione tehnologije', true, false, 2, 'utorak'),
	(99, 10, 6, 'COLLOQUIUM_1', '2027-04-01 08:00:00', '2027-04-01 10:00:00', '2027-04-01', 2, false, 'Kolokvijum 1 - Engleski jezik 2', true, false, 2, 'cetvrtak'),
	(100, 10, 6, 'COLLOQUIUM_2', '2027-05-13 08:00:00', '2027-05-13 10:00:00', '2027-05-13', 2, false, 'Kolokvijum 2 - Engleski jezik 2', true, false, 2, 'cetvrtak'),
	(101, 11, 4, 'COLLOQUIUM_1', '2026-11-02 11:00:00', '2026-11-02 13:00:00', '2026-11-02', 2, false, 'Kolokvijum 1 - Uvod u baze podataka', true, false, 1, 'ponedeljak'),
	(102, 11, 4, 'COLLOQUIUM_2', '2026-12-14 11:00:00', '2026-12-14 13:00:00', '2026-12-14', 2, false, 'Kolokvijum 2 - Uvod u baze podataka', true, false, 1, 'ponedeljak'),
	(103, 12, 1, 'COLLOQUIUM_1', '2026-11-04 11:00:00', '2026-11-04 13:00:00', '2026-11-04', 3, false, 'Kolokvijum 1 - Strukture podataka i algoritmi', true, false, 1, 'srijeda'),
	(104, 12, 1, 'COLLOQUIUM_2', '2026-12-16 11:00:00', '2026-12-16 13:00:00', '2026-12-16', 3, false, 'Kolokvijum 2 - Strukture podataka i algoritmi', true, false, 1, 'srijeda'),
	(105, 13, 8, 'COLLOQUIUM_1', '2026-11-16 14:00:00', '2026-11-16 16:00:00', '2026-11-16', 1, false, 'Kolokvijum 1 - Poslovni informacioni sistemi', true, false, 1, 'ponedeljak'),
	(106, 13, 8, 'COLLOQUIUM_2', '2026-12-25 14:00:00', '2026-12-25 16:00:00', '2026-12-25', 1, false, 'Kolokvijum 2 - Poslovni informacioni sistemi', true, false, 1, 'petak'),
	(107, 14, 3, 'COLLOQUIUM_1', '2026-11-10 08:00:00', '2026-11-10 10:00:00', '2026-11-10', 3, false, 'Kolokvijum 1 - Računarske mreže', true, false, 1, 'utorak'),
	(108, 14, 3, 'COLLOQUIUM_2', '2026-12-22 08:00:00', '2026-12-22 10:00:00', '2026-12-22', 3, false, 'Kolokvijum 2 - Računarske mreže', true, false, 1, 'utorak'),
	(109, 15, 6, 'COLLOQUIUM_1', '2026-11-19 08:00:00', '2026-11-19 10:00:00', '2026-11-19', 3, false, 'Kolokvijum 1 - Engleski jezik za informacione tehnologije 1', true, false, 1, 'cetvrtak'),
	(110, 15, 6, 'COLLOQUIUM_2', '2026-12-31 08:00:00', '2026-12-31 10:00:00', '2026-12-31', 3, false, 'Kolokvijum 2 - Engleski jezik za informacione tehnologije 1', true, false, 1, 'cetvrtak'),
	(111, 16, 9, 'COLLOQUIUM_1', '2027-03-15 08:00:00', '2027-03-15 10:00:00', '2027-03-15', 4, false, 'Kolokvijum 1 - Operativni sistemi', true, false, 2, 'ponedeljak'),
	(112, 16, 9, 'COLLOQUIUM_2', '2027-04-26 08:00:00', '2027-04-26 10:00:00', '2027-04-26', 4, false, 'Kolokvijum 2 - Operativni sistemi', true, false, 2, 'ponedeljak'),
	(113, 17, 1, 'COLLOQUIUM_1', '2027-03-24 08:00:00', '2027-03-24 10:00:00', '2027-03-24', 4, false, 'Kolokvijum 1 - Diskretna matematika', true, false, 2, 'srijeda'),
	(114, 17, 1, 'COLLOQUIUM_2', '2027-05-05 08:00:00', '2027-05-05 10:00:00', '2027-05-05', 4, false, 'Kolokvijum 2 - Diskretna matematika', true, false, 2, 'srijeda'),
	(115, 18, 6, 'COLLOQUIUM_1', '2027-04-02 14:00:00', '2027-04-02 16:00:00', '2027-04-02', 2, false, 'Kolokvijum 1 - Engleski jezik za informacione tehnologije 2', true, false, 2, 'petak'),
	(116, 18, 6, 'COLLOQUIUM_2', '2027-05-14 14:00:00', '2027-05-14 16:00:00', '2027-05-14', 2, false, 'Kolokvijum 2 - Engleski jezik za informacione tehnologije 2', true, false, 2, 'petak'),
	(117, 19, 2, 'COLLOQUIUM_1', '2027-03-16 11:00:00', '2027-03-16 13:00:00', '2027-03-16', 3, false, 'Kolokvijum 1 - Objektno programiranje 2', true, false, 2, 'utorak'),
	(118, 19, 2, 'COLLOQUIUM_2', '2027-04-27 11:00:00', '2027-04-27 13:00:00', '2027-04-27', 3, false, 'Kolokvijum 2 - Objektno programiranje 2', true, false, 2, 'utorak'),
	(119, 20, 3, 'COLLOQUIUM_1', '2027-03-18 11:00:00', '2027-03-18 13:00:00', '2027-03-18', 3, false, 'Kolokvijum 1 - Projektovanje računarskih mreža', true, false, 2, 'cetvrtak'),
	(120, 20, 3, 'COLLOQUIUM_2', '2027-04-29 11:00:00', '2027-04-29 13:00:00', '2027-04-29', 3, false, 'Kolokvijum 2 - Projektovanje računarskih mreža', true, false, 2, 'cetvrtak');
INSERT INTO public.academic_event VALUES
	(121, 21, 7, 'COLLOQUIUM_1', '2026-11-02 11:00:00', '2026-11-02 13:00:00', '2026-11-02', 4, false, 'Kolokvijum 1 - Sigurnost i zaštita informacionih sistema', true, false, 1, 'ponedeljak'),
	(122, 21, 7, 'COLLOQUIUM_2', '2026-12-14 11:00:00', '2026-12-14 13:00:00', '2026-12-14', 4, false, 'Kolokvijum 2 - Sigurnost i zaštita informacionih sistema', true, false, 1, 'ponedeljak'),
	(123, 22, 1, 'COLLOQUIUM_1', '2026-11-11 08:00:00', '2026-11-11 10:00:00', '2026-11-11', 5, false, 'Kolokvijum 1 - Vjerovatnoća i statistika', true, false, 1, 'srijeda'),
	(124, 22, 1, 'COLLOQUIUM_2', '2026-12-23 08:00:00', '2026-12-23 10:00:00', '2026-12-23', 5, false, 'Kolokvijum 2 - Vjerovatnoća i statistika', true, false, 1, 'srijeda'),
	(125, 23, 2, 'COLLOQUIUM_1', '2026-11-20 11:00:00', '2026-11-20 13:00:00', '2026-11-20', 3, false, 'Kolokvijum 1 - Napredni algoritmi', true, false, 1, 'petak'),
	(126, 23, 2, 'COLLOQUIUM_2', '2027-01-05 11:00:00', '2027-01-05 13:00:00', '2027-01-05', 3, false, 'Kolokvijum 2 - Napredni algoritmi', true, false, 1, 'utorak'),
	(127, 24, 4, 'COLLOQUIUM_1', '2026-11-03 11:00:00', '2026-11-03 13:00:00', '2026-11-03', 4, false, 'Kolokvijum 1 - Baze podataka', true, false, 1, 'utorak'),
	(128, 24, 4, 'COLLOQUIUM_2', '2026-12-15 11:00:00', '2026-12-15 13:00:00', '2026-12-15', 4, false, 'Kolokvijum 2 - Baze podataka', true, false, 1, 'utorak'),
	(129, 25, 3, 'COLLOQUIUM_1', '2026-11-05 14:00:00', '2026-11-05 16:00:00', '2026-11-05', 1, false, 'Kolokvijum 1 - Administracija računarskih mreža', true, false, 1, 'cetvrtak'),
	(130, 25, 3, 'COLLOQUIUM_2', '2026-12-17 14:00:00', '2026-12-17 16:00:00', '2026-12-17', 1, false, 'Kolokvijum 2 - Administracija računarskih mreža', true, false, 1, 'cetvrtak'),
	(131, 26, 8, 'COLLOQUIUM_1', '2027-03-15 10:00:00', '2027-03-15 12:00:00', '2027-03-15', 4, false, 'Kolokvijum 1 - Upravljanje ICT uslugama', true, false, 2, 'ponedeljak'),
	(132, 26, 8, 'COLLOQUIUM_2', '2027-04-26 10:00:00', '2027-04-26 12:00:00', '2027-04-26', 4, false, 'Kolokvijum 2 - Upravljanje ICT uslugama', true, false, 2, 'ponedeljak'),
	(133, 27, 10, 'COLLOQUIUM_1', '2027-03-24 14:00:00', '2027-03-24 16:00:00', '2027-03-24', 7, false, 'Kolokvijum 1 - Projekat', true, false, 2, 'srijeda'),
	(134, 27, 10, 'COLLOQUIUM_2', '2027-05-05 14:00:00', '2027-05-05 16:00:00', '2027-05-05', 7, false, 'Kolokvijum 2 - Projekat', true, false, 2, 'srijeda'),
	(135, 28, 11, 'COLLOQUIUM_1', '2027-04-02 14:00:00', '2027-04-02 16:00:00', '2027-04-02', 3, false, 'Kolokvijum 1 - Mašinsko učenje', true, false, 2, 'petak'),
	(136, 28, 11, 'COLLOQUIUM_2', '2027-05-14 14:00:00', '2027-05-14 16:00:00', '2027-05-14', 3, false, 'Kolokvijum 2 - Mašinsko učenje', true, false, 2, 'petak'),
	(137, 29, 5, 'COLLOQUIUM_1', '2027-03-16 14:00:00', '2027-03-16 16:00:00', '2027-03-16', 2, false, 'Kolokvijum 1 - Programiranje poslovnih sistema', true, false, 2, 'utorak'),
	(138, 29, 5, 'COLLOQUIUM_2', '2027-04-27 14:00:00', '2027-04-27 16:00:00', '2027-04-27', 2, false, 'Kolokvijum 2 - Programiranje poslovnih sistema', true, false, 2, 'utorak'),
	(139, 30, 7, 'COLLOQUIUM_1', '2027-03-18 14:00:00', '2027-03-18 16:00:00', '2027-03-18', 2, false, 'Kolokvijum 1 - Bežične i mobilne mreže', true, false, 2, 'cetvrtak'),
	(140, 30, 7, 'COLLOQUIUM_2', '2027-04-29 14:00:00', '2027-04-29 16:00:00', '2027-04-29', 2, false, 'Kolokvijum 2 - Bežične i mobilne mreže', true, false, 2, 'cetvrtak'),
	(141, 1, 1, 'EXAM', '2027-01-18 09:00:00', '2027-01-18 11:00:00', '2027-01-18', 1, false, 'Redovni ispitni rok', true, false, 1, 'ponedeljak'),
	(142, 1, 1, 'EXAM', '2027-02-01 09:00:00', '2027-02-01 11:00:00', '2027-02-01', 1, false, 'Popravni ispitni rok', true, false, 1, 'ponedeljak'),
	(143, 2, 2, 'EXAM', '2027-01-19 12:00:00', '2027-01-19 14:00:00', '2027-01-19', 1, false, 'Redovni ispitni rok', true, false, 1, 'utorak'),
	(144, 2, 2, 'EXAM', '2027-02-02 12:00:00', '2027-02-02 14:00:00', '2027-02-02', 1, false, 'Popravni ispitni rok', true, false, 1, 'utorak'),
	(145, 3, 10, 'EXAM', '2027-01-20 15:00:00', '2027-01-20 17:00:00', '2027-01-20', 1, false, 'Redovni ispitni rok', true, false, 1, 'srijeda'),
	(146, 3, 10, 'EXAM', '2027-02-03 15:00:00', '2027-02-03 17:00:00', '2027-02-03', 1, false, 'Popravni ispitni rok', true, false, 1, 'srijeda'),
	(147, 4, 11, 'EXAM', '2027-01-21 09:00:00', '2027-01-21 11:00:00', '2027-01-21', 1, false, 'Redovni ispitni rok', true, false, 1, 'cetvrtak'),
	(148, 4, 11, 'EXAM', '2027-02-04 09:00:00', '2027-02-04 11:00:00', '2027-02-04', 1, false, 'Popravni ispitni rok', true, false, 1, 'cetvrtak'),
	(149, 5, 6, 'EXAM', '2027-01-22 12:00:00', '2027-01-22 14:00:00', '2027-01-22', 1, false, 'Redovni ispitni rok', true, false, 1, 'petak'),
	(150, 5, 6, 'EXAM', '2027-02-05 12:00:00', '2027-02-05 14:00:00', '2027-02-05', 1, false, 'Popravni ispitni rok', true, false, 1, 'petak'),
	(151, 6, 9, 'EXAM', '2027-05-31 15:00:00', '2027-05-31 17:00:00', '2027-05-31', 1, false, 'Redovni ispitni rok', true, false, 2, 'ponedeljak'),
	(152, 6, 9, 'EXAM', '2027-06-14 15:00:00', '2027-06-14 17:00:00', '2027-06-14', 1, false, 'Popravni ispitni rok', true, false, 2, 'ponedeljak'),
	(153, 7, 2, 'EXAM', '2027-06-01 09:00:00', '2027-06-01 11:00:00', '2027-06-01', 2, false, 'Redovni ispitni rok', true, false, 2, 'utorak'),
	(154, 7, 2, 'EXAM', '2027-06-15 09:00:00', '2027-06-15 11:00:00', '2027-06-15', 2, false, 'Popravni ispitni rok', true, false, 2, 'utorak'),
	(155, 8, 5, 'EXAM', '2027-06-02 12:00:00', '2027-06-02 14:00:00', '2027-06-02', 2, false, 'Redovni ispitni rok', true, false, 2, 'srijeda'),
	(156, 8, 5, 'EXAM', '2027-06-16 12:00:00', '2027-06-16 14:00:00', '2027-06-16', 2, false, 'Popravni ispitni rok', true, false, 2, 'srijeda'),
	(157, 9, 3, 'EXAM', '2027-06-03 15:00:00', '2027-06-03 17:00:00', '2027-06-03', 1, false, 'Redovni ispitni rok', true, false, 2, 'cetvrtak'),
	(158, 9, 3, 'EXAM', '2027-06-17 15:00:00', '2027-06-17 17:00:00', '2027-06-17', 1, false, 'Popravni ispitni rok', true, false, 2, 'cetvrtak'),
	(159, 10, 6, 'EXAM', '2027-06-04 09:00:00', '2027-06-04 11:00:00', '2027-06-04', 2, false, 'Redovni ispitni rok', true, false, 2, 'petak'),
	(160, 10, 6, 'EXAM', '2027-06-18 09:00:00', '2027-06-18 11:00:00', '2027-06-18', 2, false, 'Popravni ispitni rok', true, false, 2, 'petak');
INSERT INTO public.academic_event VALUES
	(161, 11, 4, 'EXAM', '2027-01-18 12:00:00', '2027-01-18 14:00:00', '2027-01-18', 2, false, 'Redovni ispitni rok', true, false, 1, 'ponedeljak'),
	(162, 11, 4, 'EXAM', '2027-02-01 12:00:00', '2027-02-01 14:00:00', '2027-02-01', 2, false, 'Popravni ispitni rok', true, false, 1, 'ponedeljak'),
	(163, 12, 1, 'EXAM', '2027-01-19 15:00:00', '2027-01-19 17:00:00', '2027-01-19', 3, false, 'Redovni ispitni rok', true, false, 1, 'utorak'),
	(164, 12, 1, 'EXAM', '2027-02-02 15:00:00', '2027-02-02 17:00:00', '2027-02-02', 3, false, 'Popravni ispitni rok', true, false, 1, 'utorak'),
	(165, 13, 8, 'EXAM', '2027-01-20 09:00:00', '2027-01-20 11:00:00', '2027-01-20', 1, false, 'Redovni ispitni rok', true, false, 1, 'srijeda'),
	(166, 13, 8, 'EXAM', '2027-02-03 09:00:00', '2027-02-03 11:00:00', '2027-02-03', 1, false, 'Popravni ispitni rok', true, false, 1, 'srijeda'),
	(167, 14, 3, 'EXAM', '2027-01-21 12:00:00', '2027-01-21 14:00:00', '2027-01-21', 3, false, 'Redovni ispitni rok', true, false, 1, 'cetvrtak'),
	(168, 14, 3, 'EXAM', '2027-02-04 12:00:00', '2027-02-04 14:00:00', '2027-02-04', 3, false, 'Popravni ispitni rok', true, false, 1, 'cetvrtak'),
	(169, 15, 6, 'EXAM', '2027-01-22 15:00:00', '2027-01-22 17:00:00', '2027-01-22', 3, false, 'Redovni ispitni rok', true, false, 1, 'petak'),
	(170, 15, 6, 'EXAM', '2027-02-05 15:00:00', '2027-02-05 17:00:00', '2027-02-05', 3, false, 'Popravni ispitni rok', true, false, 1, 'petak'),
	(171, 16, 9, 'EXAM', '2027-05-31 09:00:00', '2027-05-31 11:00:00', '2027-05-31', 4, false, 'Redovni ispitni rok', true, false, 2, 'ponedeljak'),
	(172, 16, 9, 'EXAM', '2027-06-14 09:00:00', '2027-06-14 11:00:00', '2027-06-14', 4, false, 'Popravni ispitni rok', true, false, 2, 'ponedeljak'),
	(173, 17, 1, 'EXAM', '2027-06-01 12:00:00', '2027-06-01 14:00:00', '2027-06-01', 4, false, 'Redovni ispitni rok', true, false, 2, 'utorak'),
	(174, 17, 1, 'EXAM', '2027-06-15 12:00:00', '2027-06-15 14:00:00', '2027-06-15', 4, false, 'Popravni ispitni rok', true, false, 2, 'utorak'),
	(175, 18, 6, 'EXAM', '2027-06-02 15:00:00', '2027-06-02 17:00:00', '2027-06-02', 2, false, 'Redovni ispitni rok', true, false, 2, 'srijeda'),
	(176, 18, 6, 'EXAM', '2027-06-16 15:00:00', '2027-06-16 17:00:00', '2027-06-16', 2, false, 'Popravni ispitni rok', true, false, 2, 'srijeda'),
	(177, 19, 2, 'EXAM', '2027-06-03 09:00:00', '2027-06-03 11:00:00', '2027-06-03', 3, false, 'Redovni ispitni rok', true, false, 2, 'cetvrtak'),
	(178, 19, 2, 'EXAM', '2027-06-17 09:00:00', '2027-06-17 11:00:00', '2027-06-17', 3, false, 'Popravni ispitni rok', true, false, 2, 'cetvrtak'),
	(179, 20, 3, 'EXAM', '2027-06-04 12:00:00', '2027-06-04 14:00:00', '2027-06-04', 3, false, 'Redovni ispitni rok', true, false, 2, 'petak'),
	(180, 20, 3, 'EXAM', '2027-06-18 12:00:00', '2027-06-18 14:00:00', '2027-06-18', 3, false, 'Popravni ispitni rok', true, false, 2, 'petak'),
	(181, 21, 7, 'EXAM', '2027-01-18 15:00:00', '2027-01-18 17:00:00', '2027-01-18', 4, false, 'Redovni ispitni rok', true, false, 1, 'ponedeljak'),
	(182, 21, 7, 'EXAM', '2027-02-01 15:00:00', '2027-02-01 17:00:00', '2027-02-01', 4, false, 'Popravni ispitni rok', true, false, 1, 'ponedeljak'),
	(183, 22, 1, 'EXAM', '2027-01-19 09:00:00', '2027-01-19 11:00:00', '2027-01-19', 5, false, 'Redovni ispitni rok', true, false, 1, 'utorak'),
	(184, 22, 1, 'EXAM', '2027-02-02 09:00:00', '2027-02-02 11:00:00', '2027-02-02', 5, false, 'Popravni ispitni rok', true, false, 1, 'utorak'),
	(185, 23, 2, 'EXAM', '2027-01-20 12:00:00', '2027-01-20 14:00:00', '2027-01-20', 3, false, 'Redovni ispitni rok', true, false, 1, 'srijeda'),
	(186, 23, 2, 'EXAM', '2027-02-03 12:00:00', '2027-02-03 14:00:00', '2027-02-03', 3, false, 'Popravni ispitni rok', true, false, 1, 'srijeda'),
	(187, 24, 4, 'EXAM', '2027-01-21 15:00:00', '2027-01-21 17:00:00', '2027-01-21', 4, false, 'Redovni ispitni rok', true, false, 1, 'cetvrtak'),
	(188, 24, 4, 'EXAM', '2027-02-04 15:00:00', '2027-02-04 17:00:00', '2027-02-04', 4, false, 'Popravni ispitni rok', true, false, 1, 'cetvrtak'),
	(189, 25, 3, 'EXAM', '2027-01-22 09:00:00', '2027-01-22 11:00:00', '2027-01-22', 1, false, 'Redovni ispitni rok', true, false, 1, 'petak'),
	(190, 25, 3, 'EXAM', '2027-02-05 09:00:00', '2027-02-05 11:00:00', '2027-02-05', 1, false, 'Popravni ispitni rok', true, false, 1, 'petak'),
	(191, 26, 8, 'EXAM', '2027-05-31 12:00:00', '2027-05-31 14:00:00', '2027-05-31', 4, false, 'Redovni ispitni rok', true, false, 2, 'ponedeljak'),
	(192, 26, 8, 'EXAM', '2027-06-14 12:00:00', '2027-06-14 14:00:00', '2027-06-14', 4, false, 'Popravni ispitni rok', true, false, 2, 'ponedeljak'),
	(193, 27, 10, 'EXAM', '2027-06-01 15:00:00', '2027-06-01 17:00:00', '2027-06-01', 7, false, 'Redovni ispitni rok', true, false, 2, 'utorak'),
	(194, 27, 10, 'EXAM', '2027-06-15 15:00:00', '2027-06-15 17:00:00', '2027-06-15', 7, false, 'Popravni ispitni rok', true, false, 2, 'utorak'),
	(195, 28, 11, 'EXAM', '2027-06-02 09:00:00', '2027-06-02 11:00:00', '2027-06-02', 3, false, 'Redovni ispitni rok', true, false, 2, 'srijeda'),
	(196, 28, 11, 'EXAM', '2027-06-16 09:00:00', '2027-06-16 11:00:00', '2027-06-16', 3, false, 'Popravni ispitni rok', true, false, 2, 'srijeda'),
	(197, 29, 5, 'EXAM', '2027-06-03 12:00:00', '2027-06-03 14:00:00', '2027-06-03', 2, false, 'Redovni ispitni rok', true, false, 2, 'cetvrtak'),
	(198, 29, 5, 'EXAM', '2027-06-17 12:00:00', '2027-06-17 14:00:00', '2027-06-17', 2, false, 'Popravni ispitni rok', true, false, 2, 'cetvrtak'),
	(199, 30, 7, 'EXAM', '2027-06-04 15:00:00', '2027-06-04 17:00:00', '2027-06-04', 2, false, 'Redovni ispitni rok', true, false, 2, 'petak'),
	(200, 30, 7, 'EXAM', '2027-06-18 15:00:00', '2027-06-18 17:00:00', '2027-06-18', 2, false, 'Popravni ispitni rok', true, false, 2, 'petak');


-- Data for Name: academic_year; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.academic_year VALUES
	(1, '2025/2026', '2025-10-06', '2026-02-16', false),
	(2, '2026/2027', '2026-10-05', '2027-02-15', true);


-- Data for Name: config; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.config VALUES
	('schedule_deadline', '2026-10-26'),
	('schedule_locked', '0'),
	('active_academic_year', '2026/2027');


-- Data for Name: course; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.course VALUES
	(1, 'Inžinjerska matematika', 'IM101', 1, false, 3, 2, 0, false, true, NULL, 5, 11),
	(2, 'Osnove programiranja', 'OP101', 1, false, 3, 2, 1, false, true, NULL, 5, 11),
	(3, 'CAD projektovanje', 'CAD101', 1, false, 3, 2, 1, false, true, NULL, 6, 12),
	(4, 'Informacione tehnologije', 'IT101', 1, false, 3, 2, 1, false, true, NULL, 6, 12),
	(5, 'Engleski jezik 1', 'ENG101', 1, false, 2, 2, 0, false, true, NULL, 7, 13),
	(6, 'Arhitektura računarskih sistema', 'ARS102', 2, false, 3, 1, 1, false, true, NULL, 5, 11),
	(7, 'Objektno programiranje 1', 'OP1-102', 2, false, 3, 2, 1, false, true, NULL, 5, 11),
	(8, 'Uvod u web programiranje', 'UWP102', 2, false, 3, 2, 1, false, true, NULL, 6, 12),
	(9, 'Mrežne informacione tehnologije', 'MIT102', 2, false, 3, 1, 1, false, true, NULL, 6, 12),
	(10, 'Engleski jezik 2', 'ENG102', 2, false, 2, 2, 0, false, true, NULL, 7, 13),
	(11, 'Uvod u baze podataka', 'UBP203', 3, false, 3, 2, 1, false, true, NULL, 5, 11),
	(12, 'Strukture podataka i algoritmi', 'SPA203', 3, false, 3, 2, 1, false, true, NULL, 5, 11),
	(13, 'Poslovni informacioni sistemi', 'PIS203', 3, false, 3, 1, 0, false, true, NULL, 6, 12),
	(14, 'Računarske mreže', 'RM203', 3, false, 3, 2, 1, false, true, NULL, 6, 12),
	(15, 'Engleski jezik za informacione tehnologije 1', 'ENGIT203', 3, false, 2, 2, 0, false, true, NULL, 7, 13),
	(16, 'Operativni sistemi', 'OS204', 4, false, 2, 2, 1, false, true, NULL, 5, 11),
	(17, 'Diskretna matematika', 'DM204', 4, false, 3, 2, 0, false, true, NULL, 6, 12),
	(18, 'Engleski jezik za informacione tehnologije 2', 'ENGIT204', 4, false, 2, 2, 0, true, true, NULL, 7, 13),
	(19, 'Objektno programiranje 2', 'OP2-204', 4, false, 3, 2, 1, false, true, 'Softverski inženjering', 5, 11),
	(20, 'Projektovanje računarskih mreža', 'PRM204', 4, false, 3, 2, 1, false, true, 'Informaciono komunikacione tehnologije', 5, 11),
	(21, 'Sigurnost i zaštita informacionih sistema', 'SZIS305', 5, false, 3, 2, 1, false, true, NULL, 5, 11),
	(22, 'Vjerovatnoća i statistika', 'VIS305', 5, false, 3, 2, 0, false, true, NULL, 6, 12),
	(23, 'Napredni algoritmi', 'NA305', 5, true, 3, 2, 1, false, true, NULL, 7, 13),
	(24, 'Baze podataka', 'BP305', 5, true, 3, 2, 1, false, true, 'Softverski inženjering', 5, 11),
	(25, 'Administracija računarskih mreža', 'ARM305', 5, true, 3, 2, 1, false, true, 'Informaciono komunikacione tehnologije', 5, 11),
	(26, 'Upravljanje ICT uslugama', 'UICT306', 6, false, 2, 1, 1, false, true, NULL, 5, 11),
	(27, 'Projekat', 'PRJ306', 6, false, 0, 0, 2, false, true, NULL, 6, 12),
	(28, 'Mašinsko učenje', 'ML306', 6, true, 3, 1, 1, true, true, NULL, 7, 13),
	(29, 'Programiranje poslovnih sistema', 'PPS306', 6, true, 3, 2, 1, false, true, 'Softverski inženjering', 5, 11),
	(30, 'Bežične i mobilne mreže', 'BMM306', 6, true, 3, 2, 1, false, true, 'Informaciono komunikacione tehnologije', 5, 11);


-- Data for Name: course_professor; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.course_professor VALUES
	(1, 1, 1, false),
	(2, 1, 13, true),
	(3, 2, 2, false),
	(4, 2, 14, true),
	(5, 3, 10, false),
	(6, 3, 12, true),
	(7, 4, 11, false),
	(8, 4, 15, true),
	(9, 5, 6, false),
	(10, 5, 16, true),
	(11, 6, 9, false),
	(12, 6, 13, true),
	(13, 7, 2, false),
	(14, 7, 14, true),
	(15, 8, 5, false),
	(16, 8, 16, true),
	(17, 9, 3, false),
	(18, 9, 15, true),
	(19, 10, 6, false),
	(20, 10, 12, true),
	(21, 11, 4, false),
	(22, 11, 14, true),
	(23, 12, 1, false),
	(24, 12, 13, true),
	(25, 13, 8, false),
	(26, 13, 12, true),
	(27, 14, 3, false),
	(28, 14, 15, true),
	(29, 15, 6, false),
	(30, 15, 16, true),
	(31, 16, 9, false),
	(32, 16, 13, true),
	(33, 17, 1, false),
	(34, 17, 12, true),
	(35, 18, 6, false),
	(36, 18, 16, true),
	(37, 19, 2, false),
	(38, 19, 14, true),
	(39, 20, 3, false),
	(40, 20, 15, true);
INSERT INTO public.course_professor VALUES
	(41, 21, 7, false),
	(42, 21, 15, true),
	(43, 22, 1, false),
	(44, 22, 13, true),
	(45, 23, 2, false),
	(46, 23, 14, true),
	(47, 24, 4, false),
	(48, 24, 16, true),
	(49, 25, 3, false),
	(50, 25, 12, true),
	(51, 26, 8, false),
	(52, 26, 12, true),
	(53, 27, 10, false),
	(54, 27, 13, true),
	(55, 28, 11, false),
	(56, 28, 14, true),
	(57, 29, 5, false),
	(58, 29, 16, true),
	(59, 30, 7, false),
	(60, 30, 15, true);


-- Data for Name: event_professor; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.event_professor VALUES
	(1, 1, 1),
	(2, 2, 13),
	(3, 3, 2),
	(4, 4, 14),
	(5, 5, 14),
	(6, 6, 10),
	(7, 7, 12),
	(8, 8, 12),
	(9, 9, 11),
	(10, 10, 15),
	(11, 11, 15),
	(12, 12, 6),
	(13, 13, 16),
	(14, 14, 9),
	(15, 15, 13),
	(16, 16, 13),
	(17, 17, 2),
	(18, 18, 14),
	(19, 19, 14),
	(20, 20, 5),
	(21, 21, 16),
	(22, 22, 16),
	(23, 23, 3),
	(24, 24, 15),
	(25, 25, 15),
	(26, 26, 6),
	(27, 27, 12),
	(28, 28, 4),
	(29, 29, 14),
	(30, 30, 14),
	(31, 31, 1),
	(32, 32, 13),
	(33, 33, 13),
	(34, 34, 8),
	(35, 35, 12),
	(36, 36, 3),
	(37, 37, 15),
	(38, 38, 15),
	(39, 39, 6),
	(40, 40, 16);
INSERT INTO public.event_professor VALUES
	(41, 41, 9),
	(42, 42, 13),
	(43, 43, 13),
	(44, 44, 1),
	(45, 45, 12),
	(46, 46, 6),
	(47, 47, 16),
	(48, 48, 2),
	(49, 49, 14),
	(50, 50, 14),
	(51, 51, 3),
	(52, 52, 15),
	(53, 53, 15),
	(54, 54, 7),
	(55, 55, 15),
	(56, 56, 15),
	(57, 57, 1),
	(58, 58, 13),
	(59, 59, 2),
	(60, 60, 14),
	(61, 61, 14),
	(62, 62, 4),
	(63, 63, 16),
	(64, 64, 16),
	(65, 65, 3),
	(66, 66, 12),
	(67, 67, 12),
	(68, 68, 8),
	(69, 69, 12),
	(70, 70, 12),
	(71, 71, 13),
	(72, 72, 11),
	(73, 73, 14),
	(74, 74, 14),
	(75, 75, 5),
	(76, 76, 16),
	(77, 77, 16),
	(78, 78, 7),
	(79, 79, 15),
	(80, 80, 15);
INSERT INTO public.event_professor VALUES
	(81, 81, 1),
	(82, 81, 13),
	(83, 82, 1),
	(84, 82, 13),
	(85, 83, 2),
	(86, 83, 14),
	(87, 84, 2),
	(88, 84, 14),
	(89, 85, 10),
	(90, 85, 12),
	(91, 86, 10),
	(92, 86, 12),
	(93, 87, 11),
	(94, 87, 15),
	(95, 88, 11),
	(96, 88, 15),
	(97, 89, 6),
	(98, 89, 16),
	(99, 90, 6),
	(100, 90, 16),
	(101, 91, 9),
	(102, 91, 13),
	(103, 92, 9),
	(104, 92, 13),
	(105, 93, 2),
	(106, 93, 14),
	(107, 94, 2),
	(108, 94, 14),
	(109, 95, 5),
	(110, 95, 16),
	(111, 96, 5),
	(112, 96, 16),
	(113, 97, 3),
	(114, 97, 15),
	(115, 98, 3),
	(116, 98, 15),
	(117, 99, 6),
	(118, 99, 12),
	(119, 100, 6),
	(120, 100, 12);
INSERT INTO public.event_professor VALUES
	(121, 101, 4),
	(122, 101, 14),
	(123, 102, 4),
	(124, 102, 14),
	(125, 103, 1),
	(126, 103, 13),
	(127, 104, 1),
	(128, 104, 13),
	(129, 105, 8),
	(130, 105, 12),
	(131, 106, 8),
	(132, 106, 12),
	(133, 107, 3),
	(134, 107, 15),
	(135, 108, 3),
	(136, 108, 15),
	(137, 109, 6),
	(138, 109, 16),
	(139, 110, 6),
	(140, 110, 16),
	(141, 111, 9),
	(142, 111, 13),
	(143, 112, 9),
	(144, 112, 13),
	(145, 113, 1),
	(146, 113, 12),
	(147, 114, 1),
	(148, 114, 12),
	(149, 115, 6),
	(150, 115, 16),
	(151, 116, 6),
	(152, 116, 16),
	(153, 117, 2),
	(154, 117, 14),
	(155, 118, 2),
	(156, 118, 14),
	(157, 119, 3),
	(158, 119, 15),
	(159, 120, 3),
	(160, 120, 15);
INSERT INTO public.event_professor VALUES
	(161, 121, 7),
	(162, 121, 15),
	(163, 122, 7),
	(164, 122, 15),
	(165, 123, 1),
	(166, 123, 13),
	(167, 124, 1),
	(168, 124, 13),
	(169, 125, 2),
	(170, 125, 14),
	(171, 126, 2),
	(172, 126, 14),
	(173, 127, 4),
	(174, 127, 16),
	(175, 128, 4),
	(176, 128, 16),
	(177, 129, 3),
	(178, 129, 12),
	(179, 130, 3),
	(180, 130, 12),
	(181, 131, 8),
	(182, 131, 12),
	(183, 132, 8),
	(184, 132, 12),
	(185, 133, 10),
	(186, 133, 13),
	(187, 134, 10),
	(188, 134, 13),
	(189, 135, 11),
	(190, 135, 14),
	(191, 136, 11),
	(192, 136, 14),
	(193, 137, 5),
	(194, 137, 16),
	(195, 138, 5),
	(196, 138, 16),
	(197, 139, 7),
	(198, 139, 15),
	(199, 140, 7),
	(200, 140, 15);
INSERT INTO public.event_professor VALUES
	(201, 141, 1),
	(202, 141, 13),
	(203, 142, 1),
	(204, 142, 13),
	(205, 143, 2),
	(206, 143, 14),
	(207, 144, 2),
	(208, 144, 14),
	(209, 145, 10),
	(210, 145, 12),
	(211, 146, 10),
	(212, 146, 12),
	(213, 147, 11),
	(214, 147, 15),
	(215, 148, 11),
	(216, 148, 15),
	(217, 149, 6),
	(218, 149, 16),
	(219, 150, 6),
	(220, 150, 16),
	(221, 151, 9),
	(222, 151, 13),
	(223, 152, 9),
	(224, 152, 13),
	(225, 153, 2),
	(226, 153, 14),
	(227, 154, 2),
	(228, 154, 14),
	(229, 155, 5),
	(230, 155, 16),
	(231, 156, 5),
	(232, 156, 16),
	(233, 157, 3),
	(234, 157, 15),
	(235, 158, 3),
	(236, 158, 15),
	(237, 159, 6),
	(238, 159, 12),
	(239, 160, 6),
	(240, 160, 12);
INSERT INTO public.event_professor VALUES
	(241, 161, 4),
	(242, 161, 14),
	(243, 162, 4),
	(244, 162, 14),
	(245, 163, 1),
	(246, 163, 13),
	(247, 164, 1),
	(248, 164, 13),
	(249, 165, 8),
	(250, 165, 12),
	(251, 166, 8),
	(252, 166, 12),
	(253, 167, 3),
	(254, 167, 15),
	(255, 168, 3),
	(256, 168, 15),
	(257, 169, 6),
	(258, 169, 16),
	(259, 170, 6),
	(260, 170, 16),
	(261, 171, 9),
	(262, 171, 13),
	(263, 172, 9),
	(264, 172, 13),
	(265, 173, 1),
	(266, 173, 12),
	(267, 174, 1),
	(268, 174, 12),
	(269, 175, 6),
	(270, 175, 16),
	(271, 176, 6),
	(272, 176, 16),
	(273, 177, 2),
	(274, 177, 14),
	(275, 178, 2),
	(276, 178, 14),
	(277, 179, 3),
	(278, 179, 15),
	(279, 180, 3),
	(280, 180, 15);
INSERT INTO public.event_professor VALUES
	(281, 181, 7),
	(282, 181, 15),
	(283, 182, 7),
	(284, 182, 15),
	(285, 183, 1),
	(286, 183, 13),
	(287, 184, 1),
	(288, 184, 13),
	(289, 185, 2),
	(290, 185, 14),
	(291, 186, 2),
	(292, 186, 14),
	(293, 187, 4),
	(294, 187, 16),
	(295, 188, 4),
	(296, 188, 16),
	(297, 189, 3),
	(298, 189, 12),
	(299, 190, 3),
	(300, 190, 12),
	(301, 191, 8),
	(302, 191, 12),
	(303, 192, 8),
	(304, 192, 12),
	(305, 193, 10),
	(306, 193, 13),
	(307, 194, 10),
	(308, 194, 13),
	(309, 195, 11),
	(310, 195, 14),
	(311, 196, 11),
	(312, 196, 14),
	(313, 197, 5),
	(314, 197, 16),
	(315, 198, 5),
	(316, 198, 16),
	(317, 199, 7),
	(318, 199, 15),
	(319, 200, 7),
	(320, 200, 15);


-- Data for Name: holiday; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.holiday VALUES
	(1, '2026-01-01', 'Nova godina', 0),
	(2, '2026-01-02', 'Nova godina - drugi dan', 0),
	(3, '2026-01-06', 'Badnji dan', 0),
	(4, '2026-01-07', 'Božić', 0),
	(5, '2026-04-10', 'Veliki petak', 0),
	(6, '2026-04-12', 'Vaskrs', 0),
	(7, '2026-04-13', 'Vaskršnji ponedjeljak', 0),
	(8, '2026-05-01', 'Praznik rada', 0),
	(9, '2026-05-04', 'Praznik rada - drugi dan', 0),
	(10, '2026-05-21', 'Dan nezavisnosti', 0),
	(11, '2026-05-22', 'Dan nezavisnosti - drugi dan', 0),
	(12, '2026-07-13', 'Dan državnosti', 0),
	(13, '2026-07-14', 'Dan državnosti - drugi dan', 0),
	(14, '2026-11-13', 'Njegošev dan', 0),
	(15, '2026-12-19', 'Dan planine Lovćen (obiljezavanje)', 1),
	(16, '2027-01-01', 'Nova godina', 0),
	(17, '2027-01-04', 'Nova godina - drugi dan', 0),
	(18, '2027-01-06', 'Badnji dan', 0),
	(19, '2027-01-07', 'Božić', 0),
	(20, '2027-03-08', 'Dan žena (obiljezavanje)', 1),
	(21, '2027-04-30', 'Veliki petak', 0),
	(22, '2027-05-03', 'Vaskršnji ponedjeljak', 0),
	(23, '2027-05-01', 'Praznik rada', 0),
	(24, '2027-05-21', 'Dan nezavisnosti', 0),
	(25, '2027-05-24', 'Dan nezavisnosti - drugi dan', 0),
	(26, '2027-07-13', 'Dan državnosti', 0),
	(27, '2027-07-14', 'Dan državnosti - drugi dan', 0),
	(28, '2027-11-15', 'Njegošev dan', 0);


-- Data for Name: notes; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.notes VALUES
	(1, 1, '2026-10-05', 'Konsultacije pomjerene za jedan sat kasnije.'),
	(2, 1, '2026-10-16', 'Pripremiti materijal za kolokvijum.'),
	(3, 2, '2026-10-08', 'Pripremiti materijal za kolokvijum.'),
	(4, 2, '2026-10-19', 'Sastanak katedre - ne zakazivati termine.'),
	(5, 3, '2026-10-11', 'Sastanak katedre - ne zakazivati termine.'),
	(6, 3, '2026-10-22', 'Provjeriti opremu u laboratoriji prije vježbi.'),
	(7, 4, '2026-10-14', 'Provjeriti opremu u laboratoriji prije vježbi.'),
	(8, 4, '2026-10-25', 'Rok za predaju seminarskog rada.'),
	(9, 5, '2026-10-17', 'Rok za predaju seminarskog rada.'),
	(10, 5, '2026-10-28', 'Konsultacije pomjerene za jedan sat kasnije.'),
	(11, 6, '2026-10-20', 'Konsultacije pomjerene za jedan sat kasnije.'),
	(12, 6, '2026-10-31', 'Pripremiti materijal za kolokvijum.'),
	(13, 7, '2026-10-23', 'Pripremiti materijal za kolokvijum.'),
	(14, 7, '2026-11-03', 'Sastanak katedre - ne zakazivati termine.'),
	(15, 8, '2026-10-26', 'Sastanak katedre - ne zakazivati termine.'),
	(16, 8, '2026-11-06', 'Provjeriti opremu u laboratoriji prije vježbi.'),
	(17, 9, '2026-10-29', 'Provjeriti opremu u laboratoriji prije vježbi.'),
	(18, 9, '2026-11-09', 'Rok za predaju seminarskog rada.'),
	(19, 10, '2026-11-01', 'Rok za predaju seminarskog rada.'),
	(20, 10, '2026-11-12', 'Konsultacije pomjerene za jedan sat kasnije.'),
	(21, 11, '2026-11-04', 'Konsultacije pomjerene za jedan sat kasnije.'),
	(22, 11, '2026-11-15', 'Pripremiti materijal za kolokvijum.'),
	(23, 12, '2026-11-07', 'Pripremiti materijal za kolokvijum.'),
	(24, 12, '2026-11-18', 'Sastanak katedre - ne zakazivati termine.'),
	(25, 13, '2026-11-10', 'Sastanak katedre - ne zakazivati termine.'),
	(26, 13, '2026-11-21', 'Provjeriti opremu u laboratoriji prije vježbi.'),
	(27, 14, '2026-11-13', 'Provjeriti opremu u laboratoriji prije vježbi.'),
	(28, 14, '2026-11-24', 'Rok za predaju seminarskog rada.'),
	(29, 15, '2026-11-16', 'Rok za predaju seminarskog rada.'),
	(30, 15, '2026-11-27', 'Konsultacije pomjerene za jedan sat kasnije.'),
	(31, 16, '2026-11-19', 'Konsultacije pomjerene za jedan sat kasnije.'),
	(32, 16, '2026-11-30', 'Pripremiti materijal za kolokvijum.');


-- Data for Name: professor; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.professor VALUES
	(1, 'Prof. dr Nikola Vujadinović', 'nikola.vujadinovic@fit.edu.me', true),
	(2, 'Prof. dr Ana Popović', 'ana.popovic@fit.edu.me', true),
	(3, 'Prof. dr Milan Radulović', 'milan.radulovic@fit.edu.me', true),
	(4, 'Doc. dr Jelena Vuković', 'jelena.vukovic@fit.edu.me', true),
	(5, 'Prof. dr Marko Ivanović', 'marko.ivanovic@fit.edu.me', true),
	(6, 'Doc. dr Ivana Šćepanović', 'ivana.scepanovic@fit.edu.me', true),
	(7, 'Prof. dr Petar Knežević', 'petar.knezevic@fit.edu.me', true),
	(8, 'Doc. dr Milica Đukanović', 'milica.djukanovic@fit.edu.me', true),
	(9, 'Prof. dr Vladimir Mitrović', 'vladimir.mitrovic@fit.edu.me', true),
	(10, 'Doc. dr Sanja Boljević', 'sanja.boljevic@fit.edu.me', true),
	(11, 'Prof. dr Dušan Kovačević', 'dusan.kovacevic@fit.edu.me', true),
	(12, 'Mr Tamara Lekić', 'tamara.lekic@fit.edu.me', true),
	(13, 'Mr Bojan Perović', 'bojan.perovic@fit.edu.me', true),
	(14, 'Mr Katarina Vlahović', 'katarina.vlahovic@fit.edu.me', true),
	(15, 'Mr Nemanja Adžić', 'nemanja.adzic@fit.edu.me', true),
	(16, 'Mr Anđela Raičević', 'andjela.raicevic@fit.edu.me', true),
	(17, 'Doc. dr Vesna Đurović', 'vesna.djurovic@fit.edu.me', false);


-- Data for Name: professor_availability; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.professor_availability VALUES
	(1, 1, 1, '08:00:00', '12:00:00'),
	(2, 1, 3, '10:00:00', '14:00:00'),
	(3, 1, 4, '09:00:00', '13:00:00'),
	(4, 2, 2, '10:00:00', '14:00:00'),
	(5, 2, 4, '14:00:00', '18:00:00'),
	(6, 3, 3, '12:00:00', '16:00:00'),
	(7, 3, 5, '10:00:00', '14:00:00'),
	(8, 3, 1, '11:00:00', '15:00:00'),
	(9, 4, 4, '08:00:00', '12:00:00'),
	(10, 4, 1, '14:00:00', '18:00:00'),
	(11, 5, 5, '10:00:00', '14:00:00'),
	(12, 5, 2, '10:00:00', '14:00:00'),
	(13, 5, 3, '09:00:00', '13:00:00'),
	(14, 6, 1, '12:00:00', '16:00:00'),
	(15, 6, 3, '14:00:00', '18:00:00'),
	(16, 7, 2, '08:00:00', '12:00:00'),
	(17, 7, 4, '10:00:00', '14:00:00'),
	(18, 7, 5, '11:00:00', '15:00:00'),
	(19, 8, 3, '10:00:00', '14:00:00'),
	(20, 8, 5, '14:00:00', '18:00:00'),
	(21, 9, 4, '12:00:00', '16:00:00'),
	(22, 9, 1, '10:00:00', '14:00:00'),
	(23, 9, 2, '09:00:00', '13:00:00'),
	(24, 10, 5, '08:00:00', '12:00:00'),
	(25, 10, 2, '14:00:00', '18:00:00'),
	(26, 11, 1, '10:00:00', '14:00:00'),
	(27, 11, 3, '10:00:00', '14:00:00'),
	(28, 11, 4, '11:00:00', '15:00:00'),
	(29, 12, 2, '12:00:00', '16:00:00'),
	(30, 12, 4, '14:00:00', '18:00:00'),
	(31, 13, 3, '08:00:00', '12:00:00'),
	(32, 13, 5, '10:00:00', '14:00:00'),
	(33, 13, 1, '09:00:00', '13:00:00'),
	(34, 14, 4, '10:00:00', '14:00:00'),
	(35, 14, 1, '14:00:00', '18:00:00'),
	(36, 15, 5, '12:00:00', '16:00:00'),
	(37, 15, 2, '10:00:00', '14:00:00'),
	(38, 15, 3, '11:00:00', '15:00:00'),
	(39, 16, 1, '08:00:00', '12:00:00'),
	(40, 16, 3, '14:00:00', '18:00:00');


-- Data for Name: room; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.room VALUES
	(1, 'A1', 150, false, true),
	(2, 'A2', 120, false, true),
	(3, 'A3', 90, false, true),
	(4, 'B1', 60, false, true),
	(5, 'B2', 48, false, true),
	(6, 'B3', 36, false, true),
	(7, 'LAB1', 30, true, true),
	(8, 'LAB2', 28, true, true),
	(9, 'LAB3', 26, true, true),
	(10, 'LAB4', 24, true, true),
	(11, 'LAB5', 20, true, true),
	(12, 'C1', 40, false, false);


-- Data for Name: room_occupancy; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.room_occupancy VALUES
	(1, 1, 1, '17:00:00', '18:00:00', 'FEB', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(2, 1, 2, '18:00:00', '19:00:00', 'PF', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(3, 1, 3, '19:00:00', '20:00:00', 'FSJ', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(4, 1, 4, '20:00:00', '21:00:00', 'MTS', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(5, 2, 2, '18:00:00', '19:00:00', 'PF', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(6, 2, 3, '19:00:00', '20:00:00', 'FSJ', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(7, 2, 4, '20:00:00', '21:00:00', 'MTS', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(8, 2, 5, '17:00:00', '18:00:00', 'FVU', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(9, 3, 3, '19:00:00', '20:00:00', 'FSJ', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(10, 3, 4, '20:00:00', '21:00:00', 'MTS', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(11, 3, 5, '17:00:00', '18:00:00', 'FVU', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(12, 3, 1, '18:00:00', '19:00:00', 'FEB', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(13, 4, 4, '20:00:00', '21:00:00', 'MTS', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(14, 4, 5, '17:00:00', '18:00:00', 'FVU', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(15, 4, 1, '18:00:00', '19:00:00', 'FEB', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(16, 4, 2, '19:00:00', '20:00:00', 'PF', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(17, 5, 5, '17:00:00', '18:00:00', 'FVU', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(18, 5, 1, '18:00:00', '19:00:00', 'FEB', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(19, 5, 2, '19:00:00', '20:00:00', 'PF', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(20, 5, 3, '20:00:00', '21:00:00', 'FSJ', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(21, 6, 1, '18:00:00', '19:00:00', 'FEB', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(22, 6, 2, '19:00:00', '20:00:00', 'PF', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(23, 6, 3, '20:00:00', '21:00:00', 'FSJ', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(24, 6, 4, '17:00:00', '18:00:00', 'MTS', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(25, 7, 2, '19:00:00', '20:00:00', 'PF', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(26, 7, 3, '20:00:00', '21:00:00', 'FSJ', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(27, 7, 4, '17:00:00', '18:00:00', 'MTS', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(28, 7, 5, '18:00:00', '19:00:00', 'FVU', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(29, 8, 3, '20:00:00', '21:00:00', 'FSJ', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(30, 8, 4, '17:00:00', '18:00:00', 'MTS', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(31, 8, 5, '18:00:00', '19:00:00', 'FVU', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(32, 8, 1, '19:00:00', '20:00:00', 'FEB', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(33, 9, 4, '17:00:00', '18:00:00', 'MTS', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(34, 9, 5, '18:00:00', '19:00:00', 'FVU', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(35, 9, 1, '19:00:00', '20:00:00', 'FEB', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(36, 9, 2, '20:00:00', '21:00:00', 'PF', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468'),
	(37, 10, 5, '18:00:00', '19:00:00', 'FVU', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(38, 10, 1, '19:00:00', '20:00:00', 'FEB', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(39, 10, 2, '20:00:00', '21:00:00', 'PF', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(40, 10, 3, '17:00:00', '18:00:00', 'FSJ', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468');
INSERT INTO public.room_occupancy VALUES
	(41, 11, 1, '19:00:00', '20:00:00', 'FEB', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(42, 11, 2, '20:00:00', '21:00:00', 'PF', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(43, 11, 3, '17:00:00', '18:00:00', 'FSJ', 'MANUAL', 2, true, '2026-08-31 04:28:25.671468'),
	(44, 11, 4, '18:00:00', '19:00:00', 'MTS', 'SCHEDULE', 2, true, '2026-08-31 04:28:25.671468');


-- Data for Name: schedule; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.schedule VALUES
	(1, 'Zimski semestar 2026/2027'),
	(2, 'Ljetnji semestar 2026/2027');


-- Data for Name: user_account; Type: TABLE DATA; Schema: public; Owner: -

INSERT INTO public.user_account VALUES
	(1, 'admin', '$2y$10$ZKjBvyTAfTYbguGXia..l.4CceV87hP/puA.4SzKyLVfEalJFMCtq', 'ADMIN', true, NULL),
	(2, 'nikola.vujadinovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 1),
	(3, 'ana.popovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 2),
	(4, 'milan.radulovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 3),
	(5, 'jelena.vukovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 4),
	(6, 'marko.ivanovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 5),
	(7, 'ivana.scepanovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 6),
	(8, 'petar.knezevic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 7),
	(9, 'milica.djukanovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 8),
	(10, 'vladimir.mitrovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 9),
	(11, 'sanja.boljevic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 10),
	(12, 'dusan.kovacevic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 11),
	(13, 'tamara.lekic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 12),
	(14, 'bojan.perovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 13),
	(15, 'katarina.vlahovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 14),
	(16, 'nemanja.adzic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 15),
	(17, 'andjela.raicevic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', true, 16),
	(18, 'vesna.djurovic', '$2y$10$6WjZ2Puogb1lABXpYbWZk.0u3yUpZD4dRnWScczhqcB/56HOvz8UK', 'PROFESSOR', false, 17);


-- Name: academic_event_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.academic_event_id_seq', 200, true);


-- Name: academic_year_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.academic_year_id_seq', 2, true);


-- Name: course_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.course_id_seq', 30, true);


-- Name: course_professor_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.course_professor_id_seq', 60, true);


-- Name: event_professor_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.event_professor_id_seq', 320, true);


-- Name: holiday_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.holiday_id_seq', 28, true);


-- Name: notes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.notes_id_seq', 32, true);


-- Name: professor_availability_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.professor_availability_id_seq', 40, true);


-- Name: professor_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.professor_id_seq', 17, true);


-- Name: room_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.room_id_seq', 12, true);


-- Name: room_occupancy_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.room_occupancy_id_seq', 44, true);


-- Name: user_account_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -

SELECT pg_catalog.setval('public.user_account_id_seq', 18, true);


-- Name: academic_event academic_event_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.academic_event
    ADD CONSTRAINT academic_event_pkey PRIMARY KEY (id);


-- Name: academic_year academic_year_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.academic_year
    ADD CONSTRAINT academic_year_pkey PRIMARY KEY (id);


-- Name: config config_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.config
    ADD CONSTRAINT config_pkey PRIMARY KEY (key);


-- Name: course course_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.course
    ADD CONSTRAINT course_pkey PRIMARY KEY (id);


-- Name: course_professor course_professor_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.course_professor
    ADD CONSTRAINT course_professor_pkey PRIMARY KEY (id);


-- Name: course_professor course_professor_uq; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.course_professor
    ADD CONSTRAINT course_professor_uq UNIQUE (course_id, professor_id);


-- Name: event_professor event_professor_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.event_professor
    ADD CONSTRAINT event_professor_pkey PRIMARY KEY (id);


-- Name: event_professor event_professor_uq; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.event_professor
    ADD CONSTRAINT event_professor_uq UNIQUE (event_id, professor_id);


-- Name: holiday holiday_date_key; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.holiday
    ADD CONSTRAINT holiday_date_key UNIQUE (date);


-- Name: holiday holiday_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.holiday
    ADD CONSTRAINT holiday_pkey PRIMARY KEY (id);


-- Name: notes notes_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.notes
    ADD CONSTRAINT notes_pkey PRIMARY KEY (id);


-- Name: professor_availability professor_availability_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.professor_availability
    ADD CONSTRAINT professor_availability_pkey PRIMARY KEY (id);


-- Name: professor professor_email_key; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.professor
    ADD CONSTRAINT professor_email_key UNIQUE (email);


-- Name: professor professor_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.professor
    ADD CONSTRAINT professor_pkey PRIMARY KEY (id);


-- Name: room room_code_key; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.room
    ADD CONSTRAINT room_code_key UNIQUE (code);


-- Name: room_occupancy room_occupancy_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.room_occupancy
    ADD CONSTRAINT room_occupancy_pkey PRIMARY KEY (id);


-- Name: room room_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.room
    ADD CONSTRAINT room_pkey PRIMARY KEY (id);


-- Name: schedule schedule_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.schedule
    ADD CONSTRAINT schedule_pkey PRIMARY KEY (id);


-- Name: user_account user_account_pkey; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.user_account
    ADD CONSTRAINT user_account_pkey PRIMARY KEY (id);


-- Name: user_account user_account_username_key; Type: CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.user_account
    ADD CONSTRAINT user_account_username_key UNIQUE (username);


-- Name: academic_event_course_idx; Type: INDEX; Schema: public; Owner: -

CREATE INDEX academic_event_course_idx ON public.academic_event USING btree (course_id);


-- Name: academic_event_schedule_idx; Type: INDEX; Schema: public; Owner: -

CREATE INDEX academic_event_schedule_idx ON public.academic_event USING btree (schedule_id);


-- Name: academic_event_starts_idx; Type: INDEX; Schema: public; Owner: -

CREATE INDEX academic_event_starts_idx ON public.academic_event USING btree (starts_at);


-- Name: holiday_date_name_idx; Type: INDEX; Schema: public; Owner: -

CREATE UNIQUE INDEX holiday_date_name_idx ON public.holiday USING btree (date, name);


-- Name: academic_event trg_academic_event_sync_date; Type: TRIGGER; Schema: public; Owner: -

CREATE TRIGGER trg_academic_event_sync_date BEFORE INSERT OR UPDATE ON public.academic_event FOR EACH ROW EXECUTE FUNCTION public.academic_event_sync_date();


-- Name: academic_event academic_event_course_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.academic_event
    ADD CONSTRAINT academic_event_course_id_fkey FOREIGN KEY (course_id) REFERENCES public.course(id) ON DELETE CASCADE;


-- Name: academic_event academic_event_created_by_professor_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.academic_event
    ADD CONSTRAINT academic_event_created_by_professor_fkey FOREIGN KEY (created_by_professor) REFERENCES public.professor(id);


-- Name: academic_event academic_event_room_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.academic_event
    ADD CONSTRAINT academic_event_room_id_fkey FOREIGN KEY (room_id) REFERENCES public.room(id);


-- Name: course_professor course_professor_course_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.course_professor
    ADD CONSTRAINT course_professor_course_id_fkey FOREIGN KEY (course_id) REFERENCES public.course(id) ON DELETE CASCADE;


-- Name: course_professor course_professor_professor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.course_professor
    ADD CONSTRAINT course_professor_professor_id_fkey FOREIGN KEY (professor_id) REFERENCES public.professor(id) ON DELETE CASCADE;


-- Name: event_professor event_professor_event_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.event_professor
    ADD CONSTRAINT event_professor_event_id_fkey FOREIGN KEY (event_id) REFERENCES public.academic_event(id) ON DELETE CASCADE;


-- Name: event_professor event_professor_professor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.event_professor
    ADD CONSTRAINT event_professor_professor_id_fkey FOREIGN KEY (professor_id) REFERENCES public.professor(id) ON DELETE CASCADE;


-- Name: notes notes_professor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.notes
    ADD CONSTRAINT notes_professor_id_fkey FOREIGN KEY (professor_id) REFERENCES public.professor(id) ON DELETE CASCADE;


-- Name: professor_availability professor_availability_professor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.professor_availability
    ADD CONSTRAINT professor_availability_professor_id_fkey FOREIGN KEY (professor_id) REFERENCES public.professor(id) ON DELETE CASCADE;

ALTER TABLE ONLY public.professor_availability
    ADD CONSTRAINT professor_availability_preferred_room_id_fkey FOREIGN KEY (preferred_room_id) REFERENCES public.room(id);

ALTER TABLE ONLY public.professor_availability
    ADD CONSTRAINT professor_availability_course_id_fkey FOREIGN KEY (course_id) REFERENCES public.course(id);


-- Name: room_occupancy room_occupancy_academic_year_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.room_occupancy
    ADD CONSTRAINT room_occupancy_academic_year_id_fkey FOREIGN KEY (academic_year_id) REFERENCES public.academic_year(id) ON DELETE CASCADE;


-- Name: room_occupancy room_occupancy_room_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.room_occupancy
    ADD CONSTRAINT room_occupancy_room_id_fkey FOREIGN KEY (room_id) REFERENCES public.room(id) ON DELETE CASCADE;


-- Name: user_account user_account_professor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -

ALTER TABLE ONLY public.user_account
    ADD CONSTRAINT user_account_professor_id_fkey FOREIGN KEY (professor_id) REFERENCES public.professor(id) ON DELETE SET NULL;





COMMIT;

-- ------------------------------------------------------------- PROVJERA ----
SELECT 'academic_event'         AS tabela, COUNT(*) FROM public.academic_event
UNION ALL SELECT 'academic_year',          COUNT(*) FROM public.academic_year
UNION ALL SELECT 'config',                 COUNT(*) FROM public.config
UNION ALL SELECT 'course',                 COUNT(*) FROM public.course
UNION ALL SELECT 'course_professor',       COUNT(*) FROM public.course_professor
UNION ALL SELECT 'event_professor',        COUNT(*) FROM public.event_professor
UNION ALL SELECT 'holiday',                COUNT(*) FROM public.holiday
UNION ALL SELECT 'notes',                  COUNT(*) FROM public.notes
UNION ALL SELECT 'professor',              COUNT(*) FROM public.professor
UNION ALL SELECT 'professor_availability', COUNT(*) FROM public.professor_availability
UNION ALL SELECT 'room',                   COUNT(*) FROM public.room
UNION ALL SELECT 'room_occupancy',         COUNT(*) FROM public.room_occupancy
UNION ALL SELECT 'schedule',               COUNT(*) FROM public.schedule
UNION ALL SELECT 'user_account',           COUNT(*) FROM public.user_account
ORDER BY 1;
