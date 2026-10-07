<?php
declare(strict_types=1);
session_start();

$dataFile = __DIR__ . '/data/library.json';
if (!is_dir(dirname($dataFile))) mkdir(dirname($dataFile), 0775, true);

function blank_db(): array {
    return ['meta'=>['next_ids'=>['book'=>1,'tag'=>1,'shelf'=>1,'loan'=>1]],'books'=>[],'tags'=>[],'shelves'=>[],'loans'=>[]];
}
function db_load(string $file): array {
    if (!file_exists($file)) { $d=blank_db(); db_save($file,$d); return $d; }
    $d=json_decode((string)file_get_contents($file),true);
    return is_array($d) ? array_replace_recursive(blank_db(),$d) : blank_db();
}
function db_save(string $file,array $d): void {
    file_put_contents($file,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function next_id(array &$d,string $type): int {
    $id=(int)($d['meta']['next_ids'][$type]??1); $d['meta']['next_ids'][$type]=$id+1; return $id;
}
function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function row_by_id(array $rows,int $id): ?array {
    foreach($rows as $r) if((int)$r['id']===$id) return $r; return null;
}
function go(string $s): never { header('Location: index.php?section='.$s); exit; }
function flash(string $type,string $msg): void { $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }

function status_badge(string $s): string {
    $map=[
        'Available'=>'bg-success-subtle text-success',
        'Lent'=>'bg-warning-subtle text-warning',
        'Borrowed'=>'bg-warning-subtle text-warning',
        'Reading'=>'bg-info-subtle text-info',
        'Wishlist'=>'bg-purple-subtle text-purple',
        'Returned'=>'bg-success-subtle text-success',
        'Overdue'=>'bg-danger-subtle text-danger',
    ];
    return '<span class="badge '.($map[$s]??'bg-secondary-subtle text-secondary').'">'.h($s).'</span>';
}
function loan_status(array $l): string {
    if(($l['status']??'')==='Returned') return 'Returned';
    if(($l['due_date']??'')!=='' && $l['due_date']<date('Y-m-d')) return 'Overdue';
    return 'Borrowed';
}
function sync_book_statuses(string $file,array &$d): void {
    $active=[];
    foreach($d['loans'] as $l) if(($l['status']??'')!=='Returned') $active[(int)($l['book_id']??0)]=true;
    $changed=false;
    foreach($d['books'] as &$b){
        $id=(int)($b['id']??0); $st=$b['status']??'Available';
        if(isset($active[$id])){ if($st!=='Lent'){ $b['status']='Lent'; $changed=true; } }
        elseif(in_array($st,['Lent','Borrowed'],true)){ $b['status']='Available'; $changed=true; }
    }
    unset($b);
    if($changed) db_save($file,$d);
}

$d=db_load($dataFile);
$section=$_GET['section']??'dashboard';
if(!in_array($section,['dashboard','books','tags','shelves','lending'],true)) $section='dashboard';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $a=$_POST['action']??'';

    if($a==='save_book'){
        $id=(int)($_POST['book_id']??0);
        $b=[
            'id'=>$id?:next_id($d,'book'),'title'=>trim($_POST['title']??''),
            'author'=>trim($_POST['author']??''),'isbn'=>trim($_POST['isbn']??''),
            'publisher'=>trim($_POST['publisher']??''),'publication_year'=>trim($_POST['publication_year']??''),
            'edition'=>trim($_POST['edition']??''),'language'=>trim($_POST['language']??''),
            'shelf_id'=>(int)($_POST['shelf_id']??0),'status'=>trim($_POST['status']??'Available'),
            'cover_url'=>trim($_POST['cover_url']??''),'purchase_date'=>trim($_POST['purchase_date']??''),
            'purchase_price'=>trim($_POST['purchase_price']??''),'notes'=>trim($_POST['notes']??''),
            'tag_ids'=>array_values(array_unique(array_map('intval',$_POST['tag_ids']??[]))),
            'updated_at'=>date('Y-m-d H:i:s')
        ];
        if($b['title']!==''){
            $found=false;
            foreach($d['books'] as &$old) if((int)$old['id']===$id && $id>0){$b['created_at']=$old['created_at']??date('Y-m-d H:i:s');$old=$b;$found=true;break;}
            unset($old);
            if(!$found){$b['created_at']=date('Y-m-d H:i:s');$d['books'][]=$b;}
            db_save($dataFile,$d);
            flash('success',$id>0?'Book updated successfully.':'Book added to your library.');
        }
        go('books');
    }

    if($a==='delete_book'){
        $id=(int)$_POST['book_id'];
        $d['books']=array_values(array_filter($d['books'],fn($x)=>(int)$x['id']!==$id));
        $d['loans']=array_values(array_filter($d['loans'],fn($x)=>(int)$x['book_id']!==$id));
        db_save($dataFile,$d); flash('success','Book deleted.'); go('books');
    }

    if($a==='save_tag'){
        $id=(int)($_POST['tag_id']??0); $name=trim($_POST['name']??'');
        if($name!==''){
            $found=false;
            foreach($d['tags'] as &$t) if((int)$t['id']===$id && $id>0){$t['name']=$name;$found=true;break;}
            unset($t);
            if(!$found)$d['tags'][]=['id'=>next_id($d,'tag'),'name'=>$name];
            db_save($dataFile,$d);
            flash('success',$id>0?'Tag updated.':'Tag created.');
        } go('tags');
    }
    if($a==='delete_tag'){
        $id=(int)$_POST['tag_id']; $d['tags']=array_values(array_filter($d['tags'],fn($x)=>(int)$x['id']!==$id));
        foreach($d['books'] as &$b)$b['tag_ids']=array_values(array_filter($b['tag_ids']??[],fn($x)=>(int)$x!==$id));
        unset($b); db_save($dataFile,$d); flash('success','Tag deleted.'); go('tags');
    }

    if($a==='save_shelf'){
        $id=(int)($_POST['shelf_id']??0);$name=trim($_POST['name']??'');$location=trim($_POST['location']??'');
        if($name!==''){
            $found=false; foreach($d['shelves'] as &$s)if((int)$s['id']===$id&&$id>0){$s['name']=$name;$s['location']=$location;$found=true;break;}
            unset($s); if(!$found)$d['shelves'][]=['id'=>next_id($d,'shelf'),'name'=>$name,'location'=>$location];
            db_save($dataFile,$d);
            flash('success',$id>0?'Shelf updated.':'Shelf created.');
        } go('shelves');
    }
    if($a==='delete_shelf'){
        $id=(int)$_POST['shelf_id'];$d['shelves']=array_values(array_filter($d['shelves'],fn($x)=>(int)$x['id']!==$id));
        foreach($d['books'] as &$b)if((int)($b['shelf_id']??0)===$id)$b['shelf_id']=0;
        unset($b);db_save($dataFile,$d);flash('success','Shelf deleted.');go('shelves');
    }

    if($a==='save_loan'){
        $id=(int)($_POST['loan_id']??0);$book=(int)($_POST['book_id']??0);
        $l=['id'=>$id?:next_id($d,'loan'),'book_id'=>$book,'borrower'=>trim($_POST['borrower']??''),
            'loan_date'=>$_POST['loan_date']??date('Y-m-d'),'due_date'=>$_POST['due_date']??'',
            'return_date'=>'','status'=>'Borrowed','notes'=>trim($_POST['notes']??'')];
        $found=false;foreach($d['loans'] as &$x)if((int)$x['id']===$id&&$id>0){$l['return_date']=$x['return_date']??'';$x=$l;$found=true;break;}
        unset($x);if(!$found)$d['loans'][]=$l;
        foreach($d['books'] as &$b)if((int)$b['id']===$book)$b['status']='Lent';unset($b);
        db_save($dataFile,$d);flash('success','Book lent successfully.');go('lending');
    }
    if($a==='return_loan'){
        $id=(int)$_POST['loan_id'];foreach($d['loans'] as &$l)if((int)$l['id']===$id){$l['status']='Returned';$l['return_date']=date('Y-m-d');$bid=(int)$l['book_id'];foreach($d['books'] as &$b)if((int)$b['id']===$bid)$b['status']='Available';unset($b);break;}unset($l);
        db_save($dataFile,$d);flash('success','Book marked as returned.');go('lending');
    }
}

sync_book_statuses($dataFile,$d);

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);

$q=trim($_GET['q']??'');$tagq=trim($_GET['tag_q']??'');$status=trim($_GET['status']??'');$tagf=(int)($_GET['tag']??0);
$statuses=['Available','Lent','Borrowed','Reading','Wishlist'];
$books=array_values(array_filter($d['books'],function($b)use($q,$status,$tagf){
    $hay=strtolower(($b['title']??'').' '.($b['author']??'').' '.($b['isbn']??'').' '.($b['publisher']??''));
    return ($q===''||str_contains($hay,strtolower($q)))
        &&($status===''||($b['status']??'')===$status)
        &&($tagf===0||in_array($tagf,array_map('intval',$b['tag_ids']??[]),true));
}));
$tags=array_values(array_filter($d['tags'],fn($t)=>$tagq===''||str_contains(strtolower($t['name']??''),strtolower($tagq))));
$tagsAlpha=$d['tags'];
usort($tagsAlpha,fn($a,$b)=>strnatcasecmp((string)($a['name']??''),(string)($b['name']??'')));
function uniq_sorted(array $books,string $field): array {
    $out=[];
    foreach($books as $b){ $v=trim((string)($b[$field]??'')); if($v!=='') $out[$v]=$v; }
    $out=array_values($out);
    usort($out,fn($a,$b)=>strnatcasecmp($a,$b));
    return $out;
}
$authorList=uniq_sorted($d['books'],'author');
$languageList=uniq_sorted($d['books'],'language');
$publisherList=uniq_sorted($d['books'],'publisher');
$editionList=uniq_sorted($d['books'],'edition');
$yearList=uniq_sorted($d['books'],'publication_year');
$editBook=isset($_GET['edit'])?row_by_id($d['books'],(int)$_GET['edit']):null;
$editTag=isset($_GET['edit_tag'])?row_by_id($d['tags'],(int)$_GET['edit_tag']):null;
$editShelf=isset($_GET['edit_shelf'])?row_by_id($d['shelves'],(int)$_GET['edit_shelf']):null;

$activeLoans=array_values(array_filter($d['loans'],fn($l)=>($l['status']??'')!=='Returned'));
$overdueLoans=array_values(array_filter($activeLoans,fn($l)=>($l['due_date']??'')!=='' && $l['due_date']<date('Y-m-d')));
$lentCount=count(array_filter($d['books'],fn($b)=>in_array($b['status']??'',['Lent','Borrowed'],true)));
$subtitles=[
    'dashboard'=>'A quick overview of your collection',
    'books'=>'Find, add and manage your books',
    'tags'=>'Organize your books with smart tags',
    'shelves'=>'Track where every book is stored',
    'lending'=>'Keep an eye on borrowed books',
];
$flashIcons=['success'=>'check-circle-fill','danger'=>'exclamation-triangle-fill','info'=>'info-circle-fill'];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Library</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%234f46e5'/><rect x='7' y='8' width='8' height='17' rx='2' fill='white'/><rect x='17' y='8' width='8' height='17' rx='2' fill='white' opacity='.7'/></svg>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
	:root{
		--brand:#4f46e5;
		--brand-dark:#4338ca;
		--brand2:#7c3aed;
		--ink:#0f172a;
		--muted:#6b7488;
		--line:#e8ebf3;
		--bg:#f3f5fb
	}
	 body{
		background:var(--bg);
		color:var(--ink);
		font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;
		font-size:14.5px
	}
	 .topbar{
		background:#fff;
		border-bottom:1px solid var(--line);
		position:sticky;
		top:0;
		z-index:1030
	}
	 .topbar .brand-text{
		font-weight:800;
		letter-spacing:-.01em
	}
	 .sidebar{
		background:linear-gradient(180deg,#141b31 0%,#0d1226 100%);
		width:264px;
		padding:22px 16px!important
	}
	 .sidebar .brand{
		display:flex;
		align-items:center;
		gap:11px;
		color:#fff;
		font-weight:800;
		font-size:19px;
		letter-spacing:-.01em;
		margin:2px 6px 20px
	}
	 .brand-mark{
		width:38px;
		height:38px;
		border-radius:12px;
		background:linear-gradient(135deg,var(--brand),var(--brand2));
		display:grid;
		place-items:center;
		color:#fff;
		font-size:18px;
		flex:0 0 auto
	}
	 .sidebar .nav-label{
		font-size:11px;
		letter-spacing:.14em;
		text-transform:uppercase;
		color:#5d6b8a;
		margin:16px 0 8px 12px;
		font-weight:700
	}
	 .sidebar a{
		display:flex;
		align-items:center;
		gap:11px;
		color:#aab5cf;
		padding:11px 14px;
		border-radius:12px;
		margin:3px 0;
		text-decoration:none;
		font-weight:600;
		font-size:14px;
		transition:background .18s,color .18s
	}
	 .sidebar a i{
		width:18px;
		text-align:center;
		font-size:16px
	}
	 .sidebar a:hover{
		background:#1c2542;
		color:#fff
	}
	 .sidebar a.active{
		background:linear-gradient(90deg,rgba(99,102,241,.45),rgba(99,102,241,.10));
		color:#fff;
		box-shadow:inset 0 0 0 1px rgba(148,163,255,.25)
	}
	 .sidebar .side-foot{
		color:#5d6b8a;
		font-size:12px;
		margin-top:auto;
		padding:16px 12px 0;
		border-top:1px solid rgba(255,255,255,.07)
	}
	 @media(min-width:992px){
		 .sidebar{
			position:sticky;
			top:0;
			min-height:100vh;
			transform:none!important;
			visibility:visible!important
		}
		 main{
			min-height:100vh
		}
	}
	 .card{
		border:0;
		border-radius:18px;
		box-shadow:0 8px 26px rgba(15,23,42,.06)
	}
	 .card-header{
		background:transparent;
		border:0;
		font-weight:700;
		font-size:16px;
		padding:0 0 14px
	}
	 .page-title{
		font-weight:800;
		letter-spacing:-.02em
	}
	 .muted{
		color:var(--muted);
		font-size:13px
	}
	 .stat{
		font-size:30px;
		font-weight:800;
		letter-spacing:-.02em;
		line-height:1.1
	}
	 .stat-icon{
		width:46px;
		height:46px;
		border-radius:14px;
		display:grid;
		place-items:center;
		font-size:21px
	}
	 .i-indigo{
		background:rgba(79,70,229,.12);
		color:#4f46e5
	}
	 .i-cyan{
		background:rgba(8,145,178,.12);
		color:#0891b2
	}
	 .i-amber{
		background:rgba(217,119,6,.14);
		color:#d97706
	}
	 .i-rose{
		background:rgba(225,29,72,.11);
		color:#e11d48
	}
	 .btn{
		border-radius:11px;
		font-weight:600;
		padding:.55rem 1rem
	}
	 .btn-sm{
		padding:.34rem .68rem;
		font-size:13px
	}
	 .btn-primary{
		background:var(--brand);
		border-color:var(--brand);
		box-shadow:0 6px 16px rgba(79,70,229,.28)
	}
	 .btn-primary:hover,.btn-primary:focus{
		background:var(--brand-dark);
		border-color:var(--brand-dark)
	}
	 .btn-dark{
		background:#111827;
		border-color:#111827
	}
	 .form-control,.form-select{
		border-radius:11px;
		border-color:#dfe3ee;
		padding:.62rem .9rem
	}
	 .form-control:focus,.form-select:focus{
		border-color:#a5b4fc;
		box-shadow:0 0 0 .18rem rgba(99,102,241,.16)
	}
	 .form-label{
		font-size:12.5px;
		font-weight:700;
		color:#55607a;
		margin-bottom:6px
	}
	 .section-label{
		font-size:11.5px;
		font-weight:800;
		letter-spacing:.1em;
		text-transform:uppercase;
		color:#94a0b8;
		border-bottom:1px solid var(--line);
		padding-bottom:7px;
		margin-top:4px
	}
	 .input-icon{
		position:relative
	}
	 .input-icon>i{
		position:absolute;
		left:14px;
		top:50%;
		transform:translateY(-50%);
		color:#9aa4bd;
		pointer-events:none
	}
	 .input-icon>.form-control{
		padding-left:40px
	}
	 .table thead th{
		font-size:11.5px;
		text-transform:uppercase;
		letter-spacing:.06em;
		color:#8a93a9;
		font-weight:700;
		background:#f8f9fd;
		border-bottom:1px solid var(--line);
		padding:.7rem .75rem
	}
	 .table td{
		vertical-align:middle;
		padding:.8rem .75rem
	}
	 .table tbody tr:last-child td{
		border-bottom:0
	}
	 .cover{
		width:40px;
		height:56px;
		border-radius:8px;
		object-fit:cover;
		background:#eef1f8;
		border:1px solid var(--line);
		display:grid;
		place-items:center;
		color:#9aa4bd;
		flex:0 0 auto;
		overflow:hidden;
		font-size:17px
	}
	 .badge{
		font-weight:600;
		font-size:12px;
		padding:.45em .85em;
		border-radius:999px
	}
	 .text-purple{
		color:#7c3aed!important
	}
	 .bg-purple-subtle{
		background:rgba(124,58,237,.12)!important
	}
	 .text-warning{
		color:#b45309!important
	}
	 .bg-warning-subtle{
		background:rgba(217,119,6,.14)!important
	}
	 .modal-content{
		border:0;
		border-radius:20px;
		box-shadow:0 24px 60px rgba(2,6,23,.25)
	}
	 .modal-header,.modal-footer{
		border-color:#eef1f8
	}
	 .modal-title{
		font-weight:700
	}
	 .modal-dialog{
		max-height:94vh
	}
	 .modal-body{
		overflow-y:auto;
		max-height:calc(94vh - 140px);
		padding:1.4rem
	}
	 .tag-scroll{
		max-height:180px;
		overflow-y:auto;
		border:1px dashed var(--line)!important;
		border-radius:12px!important;
		background:#fafbfe
	}
	 .tag-scroll label{
		font-size:14px
	}
	 .empty{
		text-align:center;
		padding:46px 20px;
		color:var(--muted)
	}
	 .empty i{
		font-size:40px;
		color:#c6cde0;
		display:block;
		margin-bottom:12px
	}
	 .empty h6{
		font-weight:700;
		color:#475069
	}
	 .alert{
		border:0;
		border-radius:14px;
		font-weight:600;
		box-shadow:0 8px 22px rgba(15,23,42,.07)
	}
	 .list-loan{
		display:flex;
		justify-content:space-between;
		align-items:center;
		gap:12px;
		padding:12px 0;
		border-bottom:1px solid var(--line)
	}
	 .list-loan:last-child{
		border-bottom:0
	}
	 .filters-card{
		background:linear-gradient(135deg,#fff,#fbfcff)
	}
	 .section-link{
		color:var(--brand);
		text-decoration:none;
		font-weight:600
	}
	 .section-link:hover{
		text-decoration:underline
	}
</style>
</head><body>
<div class="d-flex">
<aside id="sidebar" class="sidebar offcanvas offcanvas-lg" tabindex="-1" aria-label="Main navigation">
  <div class="offcanvas-header d-lg-none border-bottom border-secondary-subtle mb-2">
    <span class="brand mb-0"><span class="brand-mark"><i class="bi bi-book-half"></i></span>My Library</span>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column p-0">
    <div class="brand d-none d-lg-flex"><span class="brand-mark"><i class="bi bi-book-half"></i></span>My Library</div>
    <div class="nav-label">Menu</div>
    <a class="<?=$section==='dashboard'?'active':''?>" href="?section=dashboard"><i class="bi bi-grid-1x2"></i>Dashboard</a>
    <a class="<?=$section==='books'?'active':''?>" href="?section=books"><i class="bi bi-book"></i>Books</a>
    <a class="<?=$section==='tags'?'active':''?>" href="?section=tags"><i class="bi bi-bookmark"></i>Tags</a>
    <a class="<?=$section==='shelves'?'active':''?>" href="?section=shelves"><i class="bi bi-archive"></i>Shelves</a>
    <a class="<?=$section==='lending'?'active':''?>" href="?section=lending"><i class="bi bi-arrow-left-right"></i>Lending</a>
    <div class="side-foot mt-auto"><i class="bi bi-database me-1"></i>Stored safely in JSON — no MySQL needed</div>
  </div>
</aside>

<div class="flex-grow-1 min-vw-0 d-flex flex-column">
  <nav class="topbar d-lg-none px-3 py-2 d-flex align-items-center gap-2">
    <button class="btn btn-light btn-sm px-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
    <span class="brand-text fs-5">My Library</span>
  </nav>

  <main class="p-4 flex-grow-1">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
      <h2 class="page-title mb-1"><?=h(ucfirst($section))?></h2>
      <div class="muted"><?=h($subtitles[$section]??'')?></div>
    </div>
    <div class="d-flex gap-2">
      <?php if($section==='books'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookModal"><i class="bi bi-plus-lg me-1"></i>Add Book</button><?php endif;?>
      <?php if($section==='tags'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tagModal"><i class="bi bi-plus-lg me-1"></i>Add Tag</button><?php endif;?>
      <?php if($section==='shelves'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#shelfModal"><i class="bi bi-plus-lg me-1"></i>Add Shelf</button><?php endif;?>
      <?php if($section==='lending'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#loanModal"><i class="bi bi-plus-lg me-1"></i>Lend Book</button><?php endif;?>
    </div>
  </div>

  <?php if($flash):?>
  <div class="alert alert-<?=h($flash['type']??'info')?> d-flex align-items-center gap-2 mb-4" id="flashAlert" role="alert">
    <i class="bi bi-<?=h($flashIcons[$flash['type']??'']??'info-circle-fill')?>"></i>
    <span><?=h($flash['msg'])?></span>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
  <?php endif;?>

<?php if($section==='dashboard'):?>
  <div class="row g-3 mb-4">
    <?php foreach([
      ['Books',count($d['books']),'bi-book','i-indigo'],
      ['Tags',count($d['tags']),'bi-bookmark','i-cyan'],
      ['Shelves',count($d['shelves']),'bi-archive','i-amber'],
      ['On Loan',$lentCount,'bi-send','i-rose'],
    ] as $x):?>
    <div class="col-sm-6 col-xl-3"><div class="card p-4 h-100">
      <div class="d-flex justify-content-between align-items-start">
        <div><div class="muted mb-2"><?=$x[0]?></div><div class="stat"><?=$x[1]?></div></div>
        <div class="stat-icon <?=$x[3]?>"><i class="bi <?=$x[2]?>"></i></div>
      </div>
    </div></div>
    <?php endforeach;?>
  </div>

  <?php if($overdueLoans):?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span><?=count($overdueLoans)?> loan(s) are past their due date.</span>
    <a href="?section=lending" class="alert-link ms-auto fw-bold">Review →</a>
  </div>
  <?php endif;?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card p-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="fw-bold mb-0">Recently Added</h5>
          <a class="section-link" href="?section=books">View all</a>
        </div>
        <?php $recent=array_slice(array_reverse($d['books']),0,8);?>
        <?php if(!$recent):?>
          <div class="empty"><i class="bi bi-book"></i><h6>No books yet</h6><p class="mb-0">Add your first book to get started.</p></div>
        <?php else:?>
        <div class="table-responsive"><table class="table">
          <thead><tr><th>Title</th><th>Author</th><th>Status</th></tr></thead>
          <tbody><?php foreach($recent as$b):?>
            <tr><td class="fw-semibold"><?=h($b['title'])?></td><td class="text-muted"><?=h($b['author'])?></td><td><?=status_badge($b['status']??'Available')?></td></tr>
          <?php endforeach;?></tbody>
        </table></div>
        <?php endif;?>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card p-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="fw-bold mb-0">Active Loans</h5>
          <a class="section-link" href="?section=lending">Manage</a>
        </div>
        <?php if(!$activeLoans):?>
          <div class="empty"><i class="bi bi-check2-circle"></i><h6>Nothing on loan</h6><p class="mb-0">All your books are on the shelf.</p></div>
        <?php else:foreach(array_slice($activeLoans,-6) as$l):$b=row_by_id($d['books'],(int)$l['book_id']);$ls=loan_status($l);?>
          <div class="list-loan">
            <div class="min-w-0">
              <div class="fw-semibold text-truncate"><?=h($b['title']??'Deleted book')?></div>
              <div class="muted"><i class="bi bi-person me-1"></i><?=h($l['borrower'])?><?=($l['due_date']??'')!==''?' · due '.h($l['due_date']):''?></div>
            </div>
            <?=status_badge($ls)?>
          </div>
        <?php endforeach;endif;?>
      </div>
    </div>
  </div>

<?php elseif($section==='books'):?>
  <div class="card p-3 mb-4 filters-card">
    <form class="row g-2 align-items-end">
      <input type="hidden" name="section" value="books">
      <div class="col-lg-5 col-md-6">
        <label class="form-label">Search</label>
        <div class="input-icon"><i class="bi bi-search"></i>
          <input class="form-control" name="q" value="<?=h($q)?>" placeholder="Title, author, ISBN, publisher...">
        </div>
      </div>
      <div class="col-lg-2 col-md-3 col-6">
        <label class="form-label">Status</label>
        <select class="form-select" name="status"><option value="">All statuses</option><?php foreach($statuses as$s):?><option <?=$status===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select>
      </div>
      <div class="col-lg-2 col-md-3 col-6">
        <label class="form-label">Tag</label>
        <select class="form-select" name="tag"><option value="">All tags</option><?php foreach($tagsAlpha as$t):$cnt=count(array_filter($d['books'],fn($b)=>in_array((int)$t['id'],array_map('intval',$b['tag_ids']??[]),true)));?><option value="<?=$t['id']?>"<?=$tagf===(int)$t['id']?' selected':''?>><?=h($t['name'])?> (<?=$cnt?>)</option><?php endforeach;?></select>
      </div>
      <div class="col-lg-3 col-md-12 d-flex gap-2">
        <button class="btn btn-primary flex-grow-1"><i class="bi bi-search me-1"></i>Search</button>
        <?php if($q!==''||$status!==''||$tagf>0):?><a class="btn btn-outline-secondary" href="?section=books">Reset</a><?php endif;?>
      </div>
    </form>
  </div>

  <div class="card p-3">
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Book</th><th class="d-none d-md-table-cell">ISBN</th><th class="d-none d-lg-table-cell">Shelf</th><th>Tags</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach($books as$b):$sh=row_by_id($d['shelves'],(int)($b['shelf_id']??0));?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-3">
              <span class="cover"><?php if(($b['cover_url']??'')!==''):?><img src="<?=h($b['cover_url'])?>" alt="" style="width:100%;height:100%;object-fit:cover" onerror="this.remove()"><?php else:?><i class="bi bi-book"></i><?php endif;?></span>
              <div class="min-w-0">
                <div class="fw-semibold"><?=h($b['title'])?></div>
                <div class="muted"><?=h($b['author'])?><?php if(($b['publication_year']??'')!==''):?> · <?=h($b['publication_year'])?><?php endif;?></div>
              </div>
            </div>
          </td>
          <td class="d-none d-md-table-cell text-muted"><?=h($b['isbn'])?:'—'?></td>
          <td class="d-none d-lg-table-cell text-muted"><?=h($sh['name']??'—')?></td>
          <td><?php $any=false;foreach($b['tag_ids']??[]as$tid):$t=row_by_id($d['tags'],(int)$tid);if($t):$any=true?><span class="badge bg-light text-secondary border"><?=h($t['name'])?></span> <?php endif;endforeach;if(!$any):?>—<?php endif;?></td>
          <td><?=status_badge($b['status']??'Available')?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?section=books&edit=<?=$b['id']?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete this book?')">
              <input type="hidden" name="action" value="delete_book"><input type="hidden" name="book_id" value="<?=$b['id']?>">
              <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach;?>
      <?php if(!$books):?>
        <tr><td colspan="6"><div class="empty"><i class="bi bi-search"></i><h6>No books found</h6><p class="mb-3">Try a different search or add a new book.</p><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bookModal"><i class="bi bi-plus-lg me-1"></i>Add Book</button></div></td></tr>
      <?php endif;?>
      </tbody>
    </table></div>
  </div>

<?php elseif($section==='tags'):?>
  <div class="card p-3 mb-4">
    <form class="row g-2">
      <input type="hidden" name="section" value="tags">
      <div class="col-md-9"><div class="input-icon"><i class="bi bi-search"></i><input class="form-control" name="tag_q" value="<?=h($tagq)?>" placeholder="Search tags..."></div></div>
      <div class="col-md-3"><button class="btn btn-dark w-100">Search</button></div>
    </form>
  </div>
  <div class="card p-3">
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Tag</th><th>Books</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach($tags as$t):$use=count(array_filter($d['books'],fn($b)=>in_array((int)$t['id'],array_map('intval',$b['tag_ids']??[]),true)));?>
        <tr>
          <td><span class="badge bg-purple-subtle text-purple">#<?=$t['id']?> · <?=h($t['name'])?></span></td>
          <td><?=$use?> book(s)</td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?section=tags&edit_tag=<?=$t['id']?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete this tag?')">
              <input type="hidden" name="action" value="delete_tag"><input type="hidden" name="tag_id" value="<?=$t['id']?>">
              <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach;?>
      <?php if(!$tags):?>
        <tr><td colspan="3"><div class="empty"><i class="bi bi-bookmark"></i><h6>No tags found</h6><p class="mb-3">Create tags to categorize your books.</p><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tagModal"><i class="bi bi-plus-lg me-1"></i>Add Tag</button></div></td></tr>
      <?php endif;?>
      </tbody>
    </table></div>
  </div>

<?php elseif($section==='shelves'):?>
  <div class="card p-3">
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Shelf</th><th>Location</th><th>Books</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach($d['shelves']as$s):$use=count(array_filter($d['books'],fn($b)=>(int)($b['shelf_id']??0)===(int)$s['id']));?>
        <tr>
          <td><div class="d-flex align-items-center gap-2"><span class="stat-icon i-amber" style="width:36px;height:36px;font-size:16px"><i class="bi bi-archive"></i></span><span class="fw-semibold"><?=h($s['name'])?></span></div></td>
          <td class="text-muted"><?=h($s['location'])?:'—'?></td>
          <td><span class="badge bg-light text-secondary border"><?=$use?> book(s)</span></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="?section=shelves&edit_shelf=<?=$s['id']?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete this shelf? Books on it will be unassigned.')">
              <input type="hidden" name="action" value="delete_shelf"><input type="hidden" name="shelf_id" value="<?=$s['id']?>">
              <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach;?>
      <?php if(!$d['shelves']):?>
        <tr><td colspan="4"><div class="empty"><i class="bi bi-archive"></i><h6>No shelves yet</h6><p class="mb-3">Create shelves to organize where your books live.</p><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#shelfModal"><i class="bi bi-plus-lg me-1"></i>Add Shelf</button></div></td></tr>
      <?php endif;?>
      </tbody>
    </table></div>
  </div>

<?php else:?>
  <div class="card p-3">
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Book</th><th>Borrower</th><th>Loan Date</th><th>Due Date</th><th>Status</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach(array_reverse($d['loans'])as$l):$b=row_by_id($d['books'],(int)$l['book_id']);$ls=loan_status($l);?>
        <tr class="<?=$ls==='Overdue'?'table-danger':''?>">
          <td class="fw-semibold"><?=h($b['title']??'Deleted book')?></td>
          <td><?=h($l['borrower'])?></td>
          <td class="text-muted"><?=h($l['loan_date'])?></td>
          <td class="text-muted"><?=h($l['due_date'])?:'—'?></td>
          <td><?=status_badge($ls)?></td>
          <td class="text-end"><?php if($ls!=='Returned'):?>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="return_loan"><input type="hidden" name="loan_id" value="<?=$l['id']?>">
              <button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1"></i>Return</button>
            </form>
          <?php else:?><span class="muted">Returned <?=h($l['return_date'])?></span><?php endif;?></td>
        </tr>
      <?php endforeach;?>
      <?php if(!$d['loans']):?>
        <tr><td colspan="6"><div class="empty"><i class="bi bi-arrow-left-right"></i><h6>No loans yet</h6><p class="mb-3">Lend a book and it will show up here.</p><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#loanModal"><i class="bi bi-plus-lg me-1"></i>Lend Book</button></div></td></tr>
      <?php endif;?>
      </tbody>
    </table></div>
  </div>
<?php endif;?>
  </main>
</div>
</div>

<!-- Book Modal -->
<div class="modal fade" id="bookModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><form method="post"><input type="hidden" name="action" value="save_book"><input type="hidden" name="book_id" value="<?=h($editBook['id']??0)?>">
<div class="modal-header"><h5 class="modal-title"><?=$editBook?'Edit Book':'Add New Book'?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><div class="row g-3">
  <div class="col-12"><div class="section-label">Main information</div></div>
  <div class="col-md-8"><label class="form-label">Title *</label><input required class="form-control" name="title" value="<?=h($editBook['title']??'')?>" placeholder="e.g. Pather Panchali" autofocus></div>
  <div class="col-md-4"><label class="form-label">Author</label><input class="form-control" name="author" list="authorList" value="<?=h($editBook['author']??'')?>" placeholder="Author name — pick or type new"></div>
  <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach($statuses as$s):?><option <?=$s===($editBook['status']??'Available')?' selected':''?>><?=$s?></option><?php endforeach;?></select></div>
  <div class="col-md-4"><label class="form-label">Shelf</label><select class="form-select" name="shelf_id"><option value="0">No shelf</option><?php foreach($d['shelves'] as $s):?><option value="<?=$s['id']?>"<?=((int)($editBook['shelf_id']??0)===(int)$s['id'])?' selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></div>
  <div class="col-md-4"><label class="form-label">Language</label><input class="form-control" name="language" list="languageList" value="<?=h($editBook['language']??'')?>" placeholder="e.g. বাংলা"></div>

  <div class="col-12"><div class="section-label">Publication details</div></div>
  <div class="col-md-4"><label class="form-label">ISBN</label><input class="form-control" name="isbn" value="<?=h($editBook['isbn']??'')?>" placeholder="978..."></div>
  <div class="col-md-4"><label class="form-label">Publisher</label><input class="form-control" name="publisher" list="publisherList" value="<?=h($editBook['publisher']??'')?>"></div>
  <div class="col-md-2"><label class="form-label">Year</label><input class="form-control" name="publication_year" list="yearList" value="<?=h($editBook['publication_year']??'')?>" placeholder="2020"></div>
  <div class="col-md-2"><label class="form-label">Edition</label><input class="form-control" name="edition" list="editionList" value="<?=h($editBook['edition']??'')?>" placeholder="1st"></div>

  <div class="col-12"><div class="section-label">Purchase &amp; cover</div></div>
  <div class="col-md-4"><label class="form-label">Purchase Date</label><input type="date" class="form-control" name="purchase_date" value="<?=h($editBook['purchase_date']??'')?>"></div>
  <div class="col-md-4"><label class="form-label">Purchase Price</label><input class="form-control" name="purchase_price" value="<?=h($editBook['purchase_price']??'')?>" placeholder="0.00"></div>
  <div class="col-md-4"><label class="form-label">Cover URL</label><div class="input-icon"><i class="bi bi-image"></i><input class="form-control" name="cover_url" value="<?=h($editBook['cover_url']??'')?>" placeholder="https://.../cover.jpg"></div></div>

  <div class="col-12"><div class="section-label">Organization</div></div>
  <div class="col-12"><label class="form-label">Tags <span class="muted fw-normal">(A–Z)</span></label><div class="tag-scroll p-3"><?php if(!$tagsAlpha):?><span class="muted">No tags yet — create them from the Tags page.</span><?php endif;?><?php foreach($tagsAlpha as $t):?><label class="me-3"><input type="checkbox" name="tag_ids[]" value="<?=$t['id']?>"<?=in_array((int)$t['id'],array_map('intval',$editBook['tag_ids']??[]),true)?' checked':''?>> <?=h($t['name'])?></label><?php endforeach;?></div></div>
  <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3" placeholder="Personal notes, condition, edition details..."><?=h($editBook['notes']??'')?></textarea></div>
  <datalist id="authorList"><?php foreach($authorList as$v):?><option value="<?=h($v)?>"><?php endforeach;?></datalist>
  <datalist id="languageList"><?php foreach($languageList as$v):?><option value="<?=h($v)?>"><?php endforeach;?></datalist>
  <datalist id="publisherList"><?php foreach($publisherList as$v):?><option value="<?=h($v)?>"><?php endforeach;?></datalist>
  <datalist id="editionList"><?php foreach($editionList as$v):?><option value="<?=h($v)?>"><?php endforeach;?></datalist>
  <datalist id="yearList"><?php foreach($yearList as$v):?><option value="<?=h($v)?>"><?php endforeach;?></datalist>
</div></div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Book</button></div>
</form></div></div></div>

<!-- Tag Modal -->
<div class="modal fade" id="tagModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><input type="hidden" name="action" value="save_tag"><input type="hidden" name="tag_id" value="<?=h($editTag['id']??0)?>">
<div class="modal-header"><h5 class="modal-title"><?=$editTag?'Edit Tag':'Add Tag'?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><label class="form-label">Tag Name</label><input required class="form-control" name="name" value="<?=h($editTag['name']??'')?>" placeholder="e.g. Fiction"></div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div></div>

<!-- Shelf Modal -->
<div class="modal fade" id="shelfModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><input type="hidden" name="action" value="save_shelf"><input type="hidden" name="shelf_id" value="<?=h($editShelf['id']??0)?>">
<div class="modal-header"><h5 class="modal-title"><?=$editShelf?'Edit Shelf':'Add Shelf'?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><label class="form-label">Shelf Name</label><input required class="form-control mb-3" name="name" value="<?=h($editShelf['name']??'')?>" placeholder="e.g. Shelf 1"><label class="form-label">Location</label><input class="form-control" name="location" value="<?=h($editShelf['location']??'')?>" placeholder="e.g. Book Cabinet"></div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div></div>

<!-- Loan Modal -->
<div class="modal fade" id="loanModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><input type="hidden" name="action" value="save_loan">
<div class="modal-header"><h5 class="modal-title">Lend Book</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
  <label class="form-label">Book</label>
  <select required class="form-select mb-3" name="book_id">
    <option value="">Select a book...</option>
    <?php foreach($d['books'] as $b): if(in_array($b['status']??'Available',['Available','Wishlist'],true)):?>
    <option value="<?=$b['id']?>"><?=h($b['title'])?><?=($b['author']??'')!==''?' — '.h($b['author']):''?></option>
    <?php endif; endforeach;?>
  </select>
  <label class="form-label">Borrower</label><input required class="form-control mb-3" name="borrower" placeholder="Who is borrowing it?">
  <div class="row g-3">
    <div class="col-6"><label class="form-label">Loan Date</label><input type="date" class="form-control" name="loan_date" value="<?=date('Y-m-d')?>"></div>
    <div class="col-6"><label class="form-label">Due Date</label><input type="date" class="form-control" name="due_date"></div>
  </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Loan</button></div>
</form></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  <?php if($editBook):?>new bootstrap.Modal(document.getElementById('bookModal')).show();<?php endif;?>
  <?php if($editTag):?>new bootstrap.Modal(document.getElementById('tagModal')).show();<?php endif;?>
  <?php if($editShelf):?>new bootstrap.Modal(document.getElementById('shelfModal')).show();<?php endif;?>
  const flash=document.getElementById('flashAlert');
  if(flash) setTimeout(()=>{bootstrap.Alert.getOrCreateInstance(flash).close();},4000);
  document.querySelectorAll('.sidebar a').forEach(a=>a.addEventListener('click',()=>{
    const off=document.getElementById('sidebar');
    if(window.bootstrap && off.classList.contains('show')) bootstrap.Offcanvas.getInstance(off)?.hide();
  }));
});
</script>
</body></html>
