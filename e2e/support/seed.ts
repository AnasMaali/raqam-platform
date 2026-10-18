import type { App } from './app';

/** Reference rows the editor picks from, inserted directly: their own screens are tested elsewhere. */
export const seed = {
    organization: (app: App, name = 'شركة الاختبار الوطنية') =>
        Number(app.sql(`INSERT INTO organizations (name, slug) VALUES ('${name}', 'test-company-${Date.now()}') RETURNING id`)),

    season: (app: App, number = 1) => Number(app.sql(`INSERT INTO podcast_seasons (season_number, title_ar) VALUES (${number}, 'الموسم الأول') RETURNING id`)),

    era: (app: App, name = 'العهد العثماني') => Number(app.sql(`INSERT INTO eras (name_ar, year_from, year_to, sort_order) VALUES ('${name}', 1517, 1917, 1) RETURNING id`)),

    /** A character with one pose, as the awareness phase will manage them; needs the id of a picture. */
    characterPose: (app: App, mediaId: number) => {
        const character = Number(app.sql(`INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('هل تعلم؟', 'did-you-know', 'يعرّف بمعلومة', '#C2410C') RETURNING id`));
        return Number(app.sql(`INSERT INTO character_poses (character_id, pose_type_code, static_media_id) VALUES (${character}, 'explaining', ${mediaId}) RETURNING id`));
    },
};
