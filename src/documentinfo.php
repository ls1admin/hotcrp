<?php
// paperinfo.php -- HotCRP document objects
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class DocumentInfo {
    public $paperStorageId = 0;
    public $paperId = 0;
    public $documentType = 0;
    public $timestamp;
    public $mimetype;
    public $mimetypeid;
    public $sha1;
    public $size;
    public $content;
    public $compression;
    public $filename;
    public $unique_filename;
    public $filestore;
    public $infoJson;
    public $infoJson_str;
    public $filterType = 0;
    public $originalStorageId;
    public $docclass;
    public $is_partial = false;
    public $error_html;

    public function __construct($p = null) {
        $this->merge($p);
    }

    private function merge($p) {
        if ($p)
            foreach ($p as $k => $v)
                $this->$k = $v;
        $this->paperStorageId = (int) $this->paperStorageId;
        $this->paperId = (int) $this->paperId;
        $this->documentType = (int) $this->documentType;
        $this->timestamp = (int) $this->timestamp;
        $this->mimetypeid = (int) $this->mimetypeid;
        $this->size = (int) $this->size;
        if (is_string($this->infoJson)) {
            $this->infoJson_str = $this->infoJson;
            $this->infoJson = json_decode($this->infoJson);
        } else if (is_associative_array($this->infoJson))
            $this->infoJson = array_to_object_recursive($this->infoJson);
        else if (!is_object($this->infoJson))
            $this->infoJson = null;
        $this->filterType = $this->filterType ? (int) $this->filterType : null;
        $this->originalStorageId = $this->originalStorageId ? (int) $this->originalStorageId : null;
        $this->docclass = HotCRPDocument::get($this->documentType);
        if (isset($this->paper) && !isset($this->content))
            $this->content = $this->paper;

        // in modern versions sha1 is set at storage time; before it wasn't
        if ($this->paperStorageId > 1 && $this->sha1 == ""
            && $this->docclass->load_content($this)) {
            $this->sha1 = sha1($this->content, true);
            Dbl::q("update PaperStorage set sha1=? where paperStorageId=?", $this->sha1, $this->paperStorageId);
        }
    }

    static public function fetch($result) {
        $di = $result ? $result->fetch_object("DocumentInfo") : null;
        if ($di && !is_int($di->paperStorageId))
            $di->merge(null);
        return $di;
    }

    static public function find_by_id($id) {
        $result = Dbl::qe("select * from PaperStorage where paperStorageId=?", $id);
        $doc = self::fetch($result);
        Dbl::free($result);
        return $doc;
    }

    static public function id_by_sha1($paperId, $dtype, $sha1) {
        if (($sha1 = Filer::binary_sha1($sha1)) !== false)
            return Dbl::fetch_ivalue("select paperStorageId from PaperStorage where paperId=? and documentType=? and sha1=?", $paperId, $dtype, $sha1) ? : false;
        else
            return false;
    }

    public function filename($filters = null) {
        return HotCRPDocument::filename($this, $filters);
    }

    public function compute_sha1() {
        $sha1 = Filer::binary_sha1($this->sha1);
        if ($sha1 === false && is_string($this->content))
            $sha1 = sha1($this->content, true);
        else if ($sha1 === false && $this->filestore && is_readable($this->filestore)) {
            if (is_executable("/usr/bin/sha1sum"))
                $cmd = "/usr/bin/sha1sum ";
            else if (is_executable("/usr/bin/shasum"))
                $cmd = "/usr/bin/shasum -a 1 ";
            if ($cmd
                && ($result = exec($cmd . escapeshellarg($this->filestore), $cmd_out, $cmd_status))
                && $cmd_status == 0
                && ($sha1 = Filer::binary_sha1(trim($result))) !== false)
                /* skip */;
            else if (($this->content = file_get_contents($this->filestore)) !== false)
                $sha1 = sha1($this->content, true);
        }
        return $sha1;
    }

    public function save() {
        // look for an existing document with same sha1; otherwise upload
        if (($sha1 = $this->compute_sha1()) !== false) {
            $this->sha1 = $sha1;
            if (($this->paperStorageId = self::id_by_sha1($this->paperId, $this->documentType, $sha1)))
                return true;
        }
        if (!$this->docclass)
            $this->docclass = HotCRPDocument::get($this->documentType);
        return $this->docclass->upload($this, (object) ["paperId" => $this->paperId]);
    }

    const L_SMALL = 1;
    const L_NOSIZE = 2;
    public function link_html($html = "", $flags = 0, $filters = null) {
        global $Conf;
        $p = HotCRPDocument::url($this, $filters);

        $finalsuffix = "";
        if ($this->documentType == DTYPE_FINAL
            || ($this->documentType > 0 && ($o = PaperOption::find($this->documentType)) && $o->final))
            $finalsuffix = "f";

        if ($this->mimetype == "application/pdf")
            list($img, $alt) = ["pdf", "[PDF]"];
        else if ($this->mimetype == "application/postscript")
            list($img, $alt) = ["postscript", "[PS]"];
        else {
            $img = "generic";
            $m = Mimetype::lookup($this->mimetype, true);
            $alt = "[" . ($m && $m->description ? : $this->mimetype) . "]";
        }

        $small = ($flags & self::L_SMALL) != 0;
        $x = '<a href="' . $p . '" class="q">'
            . Ht::img($img . $finalsuffix . ($small ? "" : "24") . ".png", $alt, $small ? "sdlimg" : "dlimg");
        if ($html)
            $x .= "&nbsp;" . $html;
        if (isset($this->size) && $this->size > 0 && !($flags && self::L_NOSIZE)) {
            $x .= "&nbsp;<span class=\"dlsize\">" . ($html ? "(" : "");
            if ($this->size > 921)
                $x .= round($this->size / 1024);
            else
                $x .= max(round($this->size / 102.4), 1) / 10;
            $x .= "kB" . ($html ? ")" : "") . "</span>";
        }
        return $x . "</a>";
    }
}
