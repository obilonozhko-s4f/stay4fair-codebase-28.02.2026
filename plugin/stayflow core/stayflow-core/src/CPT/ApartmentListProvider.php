<?php

declare(strict_types=1);

namespace StayFlow\CPT;

/**
 * Version: 1.0.4
 * RU: Вывод списка квартир.
 * - [FIX]: Поиск квартир не только по post_author, но и по ключу bsbt_owner_id.
 */
final class ApartmentListProvider
{
    public function register(): void
    {
        add_shortcode('sf_owner_apartments_list', [$this, 'renderList']);
    }

    public function renderList(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Bitte loggen Sie sich ein.</p>';
        }

        $userId = get_current_user_id();
        
        // RU: Ищем квартиры, где юзер либо автор (post_author), либо назначен через bsbt_owner_id
        global $wpdb;
        $apt_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND p.post_status != 'trash'
            AND (p.post_author = %d OR pm.meta_value = %d)
        ", $userId, $userId));

        $apartments = [];
        if (!empty($apt_ids)) {
            $apartments = get_posts([
                'post_type'      => 'mphb_room_type',
                'post__in'       => $apt_ids,
                'posts_per_page' => -1,
                'post_status'    => 'any'
            ]);
        }

        ob_start();
        ?>
        <style>
            .sf-apt-list-container { font-family: 'Segoe UI', Roboto, sans-serif; max-width: 1140px; margin: 0 auto; padding: 20px 0; }
            .sf-apt-list-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #E0B849; padding-bottom: 15px; margin-bottom: 25px; }
            .sf-apt-list-header h2 { color: #082567; margin: 0; font-size: 24px; font-weight: 700; }
            
            .sf-3d-btn { position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; transition: all 0.25s ease !important; cursor: pointer !important; display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important; background-blend-mode: overlay; font-weight: 700; font-size: 14px; text-decoration: none; text-align: center; }
            .sf-3d-btn::before { content: "" !important; position: absolute !important; top: 2% !important; left: 6% !important; width: 88% !important; height: 55% !important; background: radial-gradient(ellipse at center, rgba(255,255,255,0.65) 0%, rgba(255,255,255,0.00) 72%) !important; transform: scaleY(0.48) !important; filter: blur(5px) !important; opacity: 0.55 !important; z-index: 1 !important; }
            .sf-3d-btn:hover { background-color: #082567 !important; color: #E0B849 !important; transform: translateY(-2px) !important; }
            
            .sf-3d-btn-navy { background-color: #082567 !important; color: #E0B849 !important; }
            .sf-3d-btn-navy:hover { background-color: #E0B849 !important; color: #082567 !important; }

            .sf-apt-card { display: flex; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: all 0.3s; position: relative; }
            .sf-apt-card:hover { border-color: #cbd5e1; box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
            .sf-apt-img { width: 240px; min-height: 160px; background-color: #f1f5f9; background-size: cover; background-position: center; border-right: 1px solid #e2e8f0; }
            .sf-apt-info { padding: 20px 25px; flex: 1; display: flex; flex-direction: column; justify-content: center; }
            .sf-apt-title { font-size: 20px; font-weight: 700; color: #082567; margin: 0 0 8px 0; padding-right: 220px; }
            .sf-apt-meta { font-size: 14px; color: #64748b; margin: 0 0 12px 0; }
            
            .sf-status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
            .sf-status-publish { background-color: #dcfce7; color: #0f766e; }
            .sf-status-pending { background-color: #fef9c3; color: #b45309; }
            .sf-status-draft { background-color: #f1f5f9; color: #64748b; }

            .sf-card-actions { position: absolute; top: 20px; right: 20px; display: flex; gap: 8px; }
            .sf-action-btn { background: #f8fafc; color: #082567; border: 1px solid #cbd5e1; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; transition: 0.2s; display: flex; align-items: center; gap: 5px; }
            .sf-action-btn:hover { background: #082567; color: #fff; border-color: #082567; }

            @media (max-width: 768px) {
                .sf-apt-list-header { flex-direction: column; align-items: flex-start; gap: 15px; }
                .sf-apt-card { flex-direction: column; }
                .sf-apt-img { width: 100%; height: 200px; border-right: none; border-bottom: 1px solid #e2e8f0; }
                .sf-card-actions { position: static; display: flex; width: 100%; justify-content: flex-start; margin-top: 15px; padding: 0 20px 20px 20px; box-sizing: border-box; }
                .sf-apt-title { padding-right: 0; }
                .sf-apt-info { padding-bottom: 0; }
            }
        </style>

        <div class="sf-apt-list-container">
            
            <div class="sf-apt-list-header">
                <h2>Meine Apartments</h2>
                <a href="<?php echo home_url('/add-apartment/'); ?>" class="sf-3d-btn">+ Neues Apartment</a>
            </div>

            <?php if (empty($apartments)): ?>
                <div style="text-align: center; padding: 60px 20px; background: #f8fafc; border-radius: 12px; border: 2px dashed #cbd5e1;">
                    <span style="font-size: 48px; display: block; margin-bottom: 15px;">🏠</span>
                    <h3 style="color: #082567; margin: 0 0 10px 0; font-size: 22px;">Sie haben noch keine Apartments</h3>
                    <p style="color: #64748b; font-size: 15px; margin-bottom: 25px;">Fügen Sie Ihr erstes Apartment hinzu, um Gastgeber zu werden.</p>
                    <a href="<?php echo home_url('/add-apartment/'); ?>" class="sf-3d-btn">Jetzt starten</a>
                </div>
            <?php else: ?>
                
                <?php foreach ($apartments as $apt): 
                    $thumb_url = get_the_post_thumbnail_url($apt->ID, 'medium') ?: '';
                    $address   = get_post_meta($apt->ID, 'address', true);
                    $price     = get_post_meta($apt->ID, '_sf_selling_price', true);
                    
                    if ($apt->post_status === 'publish') {
                        $status_class = 'sf-status-publish';
                        $status_text  = '🟢 Aktiv';
                    } elseif ($apt->post_status === 'pending') {
                        $status_class = 'sf-status-pending';
                        $status_text  = '🟡 In Prüfung';
                    } else {
                        $status_class = 'sf-status-draft';
                        $status_text  = '🔴 Pausiert / Offline';
                    }

                    $edit_url = add_query_arg('apt_id', $apt->ID, home_url('/edit-apartment/'));
                    $view_url = get_permalink($apt->ID);
                ?>
                    <div class="sf-apt-card">
                        <?php if ($thumb_url): ?>
                            <div class="sf-apt-img" style="background-image: url('<?php echo esc_url($thumb_url); ?>');"></div>
                        <?php else: ?>
                            <div class="sf-apt-img" style="display: flex; align-items: center; justify-content: center; font-size: 24px; color: #cbd5e1;">📷</div>
                        <?php endif; ?>
                        
                        <div class="sf-apt-info">
                            <h3 class="sf-apt-title"><?php echo esc_html($apt->post_title); ?></h3>
                            <p class="sf-apt-meta">
                                <?php if ($address) echo esc_html($address) . ' • '; ?>
                                <?php if ($price) echo '<strong>€' . esc_html($price) . '</strong> / Nacht'; ?>
                            </p>
                            <div>
                                <span class="sf-status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </div>
                        </div>

                        <div class="sf-card-actions">
                            <?php if ($apt->post_status === 'publish'): ?>
                                <a href="<?php echo esc_url($view_url); ?>" class="sf-action-btn" target="_blank" title="Auf der Website ansehen">👁️ Ansehen</a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="sf-action-btn">✏️ Bearbeiten</a>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
            
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex;">
                <a href="<?php echo home_url('/owner-dashboard/'); ?>" class="sf-3d-btn sf-3d-btn-navy">← Zurück zum Dashboard</a>
            </div>

        </div>

        <?php
        return ob_get_clean();
    }
}