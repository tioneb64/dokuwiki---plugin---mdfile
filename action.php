<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * Mdfile : ouvre, lit et modifie les fichiers Markdown (.md) du gestionnaire de médias.
 *
 *   ?do=mdfile&media=espace:fichier.md            lecture (rendu Markdown)
 *   ?do=mdfile&media=espace:fichier.md&edit=1     édition (aperçu, enregistrement)
 *
 * Les liens vers un tel fichier ({{espace:fichier.md}}) ouvrent cette vue au lieu de le télécharger.
 * Le plugin « mmdfile » étend cette classe pour les fichiers de diagramme Mermaid.
 */
class action_plugin_mdfile extends ActionPlugin
{
    /** Nom de l'action (?do=...) */
    protected $act = 'mdfile';

    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'actPreprocess');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'tplUnknown');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'rewriteLinks');
    }

    // ---- points d'extension ------------------------------------------------------------------

    /** Texte DokuWiki à analyser pour afficher le contenu brut du fichier */
    protected function wikitext(string $raw): string
    {
        return $raw;
    }

    /** HTML ajouté sous le rendu (par exemple la source) */
    protected function extraView(string $raw): string
    {
        return '';
    }

    // ---- outils -------------------------------------------------------------------------------

    /** @return string[] extensions gérées, en minuscules */
    public function exts(): array
    {
        $list = array_map('trim', explode(',', strtolower((string)$this->getConf('exts'))));
        return array_values(array_filter($list, 'strlen'));
    }

    /** Nom de l'action (?do=...) */
    public function act(): string
    {
        return $this->act;
    }

    /**
     * Chemin réel du fichier : le gestionnaire de médias d'abord, puis le dossier des pages
     * (data/pages/espace/fichier.md, nom d'origine avec majuscules ou espaces accepté).
     * Sans fichier existant, retourne le chemin dans les médias (création).
     */
    public function fileFN(string $id): string
    {
        global $conf;
        $media = mediaFN($id);
        if (file_exists($media)) return $media;
        $ns = getNS($id);
        $dir = $conf['datadir'] . ($ns === '' ? '' : '/' . str_replace(':', '/', $ns));
        $name = noNS($id);
        if (is_file($dir . '/' . $name)) return $dir . '/' . $name;
        foreach (is_dir($dir) ? scandir($dir) : [] as $entry) {
            if ($entry[0] !== '.' && is_file($dir . '/' . $entry) && cleanID($entry) === $name) return $dir . '/' . $entry;
        }
        return $media;
    }

    /** Identifiant de média demandé, s'il a une extension gérée */
    protected function mediaId(): string
    {
        global $INPUT;
        $id = cleanID($INPUT->str('media'));
        $ext = strtolower(pathinfo($id, PATHINFO_EXTENSION));
        return ($id !== '' && in_array($ext, $this->exts(), true)) ? $id : '';
    }

    protected function canRead(string $id): bool
    {
        return auth_quickaclcheck(getNS($id) . ':*') >= AUTH_READ;
    }

    protected function canEdit(string $id): bool
    {
        return auth_quickaclcheck(getNS($id) . ':*') >= AUTH_UPLOAD;
    }

    protected function url(string $id, array $more = [], string $sep = '&'): string
    {
        return wl('', array_merge(['do' => $this->act, 'media' => $id], $more), false, $sep);
    }

    // ---- traitement (avant l'affichage) -------------------------------------------------------

    public function actPreprocess(Event $event)
    {
        if ($event->data !== $this->act) return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        $id = $this->mediaId();
        if ($id === '' || !$this->canRead($id)) return;
        if (!$INPUT->post->has('save')) return;

        // enregistrement
        if (!checkSecurityToken()) {
            msg($this->getLang('err_token'), -1);
            return;
        }
        if (!$this->canEdit($id)) {
            msg($this->getLang('err_denied'), -1);
            return;
        }

        $file = $this->fileFN($id);
        $text = str_replace("\r\n", "\n", $INPUT->post->str('text'));
        $existed = file_exists($file);
        $old = $existed ? filesize($file) : 0;
        if ($existed) media_saveOldRevision($id);
        io_makeFileDir($file);
        if (!io_saveFile($file, $text)) {
            msg($this->getLang('err_write'), -1);
            return;
        }
        addMediaLogEntry(
            time(),
            $id,
            $existed ? DOKU_CHANGE_TYPE_EDIT : DOKU_CHANGE_TYPE_CREATE,
            '',
            '',
            null,
            strlen($text) - $old
        );
        msg($this->getLang('saved'), 1);
        send_redirect($this->url($id));
    }

    // ---- affichage ----------------------------------------------------------------------------

    public function tplUnknown(Event $event)
    {
        if ($event->data !== $this->act) return;
        $event->preventDefault();

        global $INPUT;
        $id = $this->mediaId();
        if ($id === '') {
            echo '<p>' . hsc($this->getLang('err_media')) . '</p>';
            return;
        }
        if (!$this->canRead($id)) {
            echo '<p>' . hsc($this->getLang('err_denied')) . '</p>';
            return;
        }

        $file = $this->fileFN($id);
        $exists = file_exists($file);
        $editing = $INPUT->bool('edit') || $INPUT->post->has('preview');

        echo '<div class="plugin_mdfile">';
        echo '<h1>' . hsc(noNS($id)) . '</h1>';

        if ($editing && $this->canEdit($id)) {
            $raw = $INPUT->post->has('text')
                ? str_replace("\r\n", "\n", $INPUT->post->str('text'))
                : ($exists ? (string)io_readFile($file) : '');
            $this->editor($id, $raw, $INPUT->post->has('preview'));
        } elseif (!$exists) {
            echo '<p>' . hsc($this->getLang('missing')) . '</p>';
            if ($this->canEdit($id)) {
                echo '<p><a class="button" href="' . hsc($this->url($id, ['edit' => 1])) . '">'
                    . hsc($this->getLang('create')) . '</a></p>';
            }
        } else {
            $raw = (string)io_readFile($file);
            $this->toolbar($id);
            echo '<div class="mdfile-content">' . $this->render($raw, $id) . '</div>';
            echo $this->extraView($raw);
        }
        echo '</div>';
    }

    protected function toolbar(string $id)
    {
        echo '<p class="mdfile-bar">';
        if ($this->canEdit($id)) {
            echo '<a class="button" href="' . hsc($this->url($id, ['edit' => 1])) . '">'
                . hsc($this->getLang('edit')) . '</a> ';
        }
        echo '<a class="button" href="' . hsc(ml($id, ['cache' => 'nocache'], true, '&', true))
            . '" download>' . hsc($this->getLang('download')) . '</a>';
        echo ' <span class="mdfile-id">' . hsc($id) . '</span></p>';
    }

    protected function editor(string $id, string $raw, bool $preview)
    {
        $this->toolbar($id);
        echo '<form method="post" action="' . hsc(script()) . '" class="mdfile-form" accept-charset="utf-8">';
        echo '<input type="hidden" name="do" value="' . hsc($this->act) . '" />';
        echo '<input type="hidden" name="media" value="' . hsc($id) . '" />';
        echo formSecurityToken(false);
        echo '<textarea name="text" class="edit" rows="26" cols="80" spellcheck="false" '
            . 'style="width:100%;font-family:monospace;box-sizing:border-box">' . hsc($raw) . '</textarea>';
        echo '<p><button type="submit" name="save" value="1" class="button">' . hsc($this->getLang('save')) . '</button> ';
        echo '<button type="submit" name="preview" value="1" class="button">' . hsc($this->getLang('preview')) . '</button> ';
        echo '<a class="button" href="' . hsc($this->url($id)) . '">' . hsc($this->getLang('cancel')) . '</a></p>';
        echo '</form>';
        if ($preview) {
            echo '<h2>' . hsc($this->getLang('preview')) . '</h2>';
            echo '<div class="mdfile-content">' . $this->render($raw, $id) . '</div>';
        }
    }

    /** Rendu XHTML du contenu ; les liens relatifs sont résolus depuis l'espace du fichier */
    protected function render(string $raw, string $id): string
    {
        global $ID, $conf;
        $oldId = $ID;
        $oldSec = $conf['maxseclevel'];
        $ID = $id;                       // pseudo-page : espace de noms de référence
        $conf['maxseclevel'] = 0;        // pas de boutons d'édition de section
        $info = [];
        $html = (string)p_render('xhtml', p_get_instructions($this->wikitext($raw)), $info);
        $ID = $oldId;
        $conf['maxseclevel'] = $oldSec;
        return preg_replace('#<div class="secedit[^"]*">.*?</div>#s', '', $html);
    }

    /** Les liens vers un fichier géré ouvrent la vue du plugin au lieu de le télécharger */
    public function rewriteLinks(Event $event)
    {
        if (!is_string($event->data) || strpos($event->data, 'fetch.php') === false) return;
        $exts = implode('|', array_map('preg_quote', $this->exts()));
        $event->data = preg_replace_callback(
            '#<a ([^>]*?)href="[^"]*fetch\.php(?:/([^"?]+)|\?(?:[^"]*?&amp;)?media=([^"&]+))[^"]*"#',
            function ($m) use ($exts) {
                $id = cleanID(rawurldecode($m[3] ?? $m[2]));
                if (!preg_match('#\.(' . $exts . ')$#i', $id)) return $m[0];
                return '<a ' . $m[1] . 'href="' . hsc($this->url($id, [], '&')) . '"';
            },
            $event->data
        );
    }
}
