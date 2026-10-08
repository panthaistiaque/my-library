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
function row_by_id(array $rows,int $id) {
    foreach($rows as $r) if((int)$r['id']===$id) return $r; return null;
}
function go(string $s) { header('Location: index.php?section='.$s); exit; }
function flash(string $type,string $msg): void { $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }

function status_badge(string $s): string {
    $map=[
        'Available'=>'bg-success-subtle text-success-emphasis',
        'Lent'=>'bg-warning-subtle text-warning-emphasis',
        'Borrowed'=>'bg-warning-subtle text-warning-emphasis',
        'Reading'=>'bg-info-subtle text-info-emphasis',
        'Wishlist'=>'bg-primary-subtle text-primary-emphasis',
        'Returned'=>'bg-success-subtle text-success-emphasis',
        'Overdue'=>'bg-danger-subtle text-danger-emphasis',
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
        $d['books']=array_values(array_filter($d['books'],function($x) use ($id){ return (int)$x['id']!==$id; }));
        $d['loans']=array_values(array_filter($d['loans'],function($x) use ($id){ return (int)$x['book_id']!==$id; }));
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
        $id=(int)$_POST['tag_id']; $d['tags']=array_values(array_filter($d['tags'],function($x) use ($id){ return (int)$x['id']!==$id; }));
        foreach($d['books'] as &$b)$b['tag_ids']=array_values(array_filter($b['tag_ids']??[],function($x) use ($id){ return (int)$x!==$id; }));
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
        $id=(int)$_POST['shelf_id'];$d['shelves']=array_values(array_filter($d['shelves'],function($x) use ($id){ return (int)$x['id']!==$id; }));
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
$books=array_values(array_filter($d['books'],function($b) use ($q,$status,$tagf){
    $hay=strtolower(($b['title']??'').' '.($b['author']??'').' '.($b['isbn']??'').' '.($b['publisher']??''));
    $qOk = ($q==='' || strpos($hay, strtolower($q)) !== false);
    $sOk = ($status==='' || ($b['status']??'')===$status);
    $tOk = ($tagf===0 || in_array($tagf,array_map('intval',$b['tag_ids']??[]),true));
    return $qOk && $sOk && $tOk;
}));
$tags=array_values(array_filter($d['tags'],function($t) use ($tagq){ return $tagq==='' || strpos(strtolower($t['name']??''),strtolower($tagq)) !== false; }));
$tagsAlpha=$d['tags'];
usort($tagsAlpha,function($a,$b){ return strnatcasecmp((string)($a['name']??''),(string)($b['name']??'')); });
function uniq_sorted(array $books,string $field): array {
    $out=[];
    foreach($books as $b){ $v=trim((string)($b[$field]??'')); if($v!=='') $out[$v]=$v; }
    $out=array_values($out);
    usort($out,function($a,$b){ return strnatcasecmp($a,$b); });
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

$activeLoans=array_values(array_filter($d['loans'],function($l){ return ($l['status']??'')!=='Returned'; }));
$overdueLoans=array_values(array_filter($activeLoans,function($l){ return ($l['due_date']??'')!=='' && $l['due_date']<date('Y-m-d'); }));
$lentCount=count(array_filter($d['books'],function($b){ return in_array($b['status']??'',['Lent','Borrowed'],true); }));
$tagUsage=[];
foreach($d['tags'] as $t){
    $cnt=count(array_filter($d['books'],function($b)use($t){ return in_array((int)$t['id'],array_map('intval',$b['tag_ids']??[]),true); }));
    $tagUsage[]=['id'=>(int)$t['id'],'name'=>(string)($t['name']??''),'count'=>$cnt];
}
usort($tagUsage,function($a,$b){ return ($b['count']<=>$a['count']) ?: strnatcasecmp($a['name'],$b['name']); });
$topTags=array_slice($tagUsage,0,6);
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
<style>
	@media (min-width:992px){
		.sidebar{ position:sticky; top:0; width:268px; height:100vh; transform:none!important; visibility:visible!important; }
		main{ min-height:100vh; }
	}
	.sidebar{ width:268px; background-color:#10162b !important; }
	.sidebar .offcanvas-body{ flex-grow:1; }
	.brand-mark{ display:grid; place-items:center; width:40px; height:40px; border-radius:.7rem; background:var(--bs-primary); color:#fff; font-size:1.1rem; flex:0 0 auto; }
	.sidebar .nav-pills .nav-link,
	.sidebar .nav-pills .nav-link:focus,
	.sidebar .nav-pills .nav-link:hover{ color:#a9b3cc; border-radius:.75rem; }
	.sidebar .nav-pills .nav-link.active{ background-color:var(--bs-primary); color:#fff; }
	.cover{ display:grid; place-items:center; width:44px; height:60px; border-radius:.5rem; background:var(--bs-tertiary-bg); border:1px solid var(--bs-border-color); color:var(--bs-secondary-color); overflow:hidden; font-size:1.05rem; }
	.cover img{ width:100%; height:100%; object-fit:cover; }
	.input-icon{ position:relative; }
	.input-icon>i{ position:absolute; left:.8rem; top:50%; transform:translateY(-50%); color:var(--bs-secondary-color); pointer-events:none; }
	.input-icon>.form-control{ padding-left:2.4rem; }
	.section-label{ font-size:.78rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--bs-secondary-color); border-bottom:1px dashed var(--bs-border-color); padding-bottom:.5rem; margin-top:.25rem; }
	.tag-scroll{ max-height:180px; overflow-y:auto; border:1px dashed var(--bs-border-color); border-radius:.75rem; background:var(--bs-tertiary-bg); }
	.tag-scroll label{ font-size:.9rem; }
	.list-loan{ display:flex; justify-content:space-between; align-items:center; gap:.75rem; padding:.9rem 0; border-bottom:1px dashed var(--bs-border-color); }
	.list-loan:last-child{ border-bottom:0; }
	.muted{ color:var(--bs-secondary-color); font-size:.85rem; }
	.tag-stat{ color:inherit; text-decoration:none; }
	.min-w-0{ min-width:0; }
	.empty{ text-align:center; padding:3rem 1.25rem; }
	.empty i{ font-size:2.6rem; color:var(--bs-secondary-color); opacity:.5; display:block; margin-bottom:.75rem; }
	.empty h6{ font-weight:600; }
	@keyframes fadeUp{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
	.fade-up{ animation:fadeUp .35s ease both; }
	.fade-up-1{ animation-delay:.03s; } .fade-up-2{ animation-delay:.08s; } .fade-up-3{ animation-delay:.13s; } .fade-up-4{ animation-delay:.18s; }
</style>
</head><body>
<div class="d-flex">
<aside id="sidebar" class="sidebar offcanvas offcanvas-lg" tabindex="-1" aria-label="Main navigation">
  <div class="offcanvas-header d-lg-none border-bottom border-secondary-subtle mb-2">
    <span class="fw-bold fs-5 text-white d-flex align-items-center gap-2 mb-0"><span class="brand-mark"><i class="bi bi-book-half"></i></span>My Library</span>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column p-0">
    <div class="fw-bold fs-5 text-white d-flex align-items-center gap-2 px-3 pt-2 pb-3 d-none d-lg-flex"><span class="brand-mark"><i class="bi bi-book-half"></i></span>My Library</div>
    <div class="small text-uppercase fw-semibold text-secondary px-3 pb-2">Menu</div>
    <nav class="nav nav-pills flex-column px-3">
      <a class="nav-link <?=$section==='dashboard'?'active':''?>" href="?section=dashboard"><i class="bi bi-grid-1x2 me-2"></i>Dashboard</a>
      <a class="nav-link <?=$section==='books'?'active':''?>" href="?section=books"><i class="bi bi-book me-2"></i>Books</a>
      <a class="nav-link <?=$section==='tags'?'active':''?>" href="?section=tags"><i class="bi bi-bookmark me-2"></i>Tags</a>
      <a class="nav-link <?=$section==='shelves'?'active':''?>" href="?section=shelves"><i class="bi bi-archive me-2"></i>Shelves</a>
      <a class="nav-link <?=$section==='lending'?'active':''?>" href="?section=lending"><i class="bi bi-arrow-left-right me-2"></i>Lending</a>
    </nav>
    <div class="small text-secondary border-top border-secondary-subtle p-3 mt-auto"><i class="bi bi-database me-1"></i>Stored safely in JSON</div>
  </div>
</aside>

<div class="flex-grow-1 min-vw-0 d-flex flex-column">
  <nav class="topbar d-lg-none px-3 py-2 d-flex align-items-center gap-2 bg-white position-sticky top-0 z-3 shadow-sm">
    <button class="btn btn-outline-secondary btn-sm px-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
    <span class="fw-bold fs-5">My Library</span>
  </nav>

  <main class="p-4 flex-grow-1">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 fade-up">
    <div>
      <h2 class="fw-bold mb-1"><?=h(ucfirst($section))?></h2>
    </div>
    <div class="d-flex gap-2">
      <?php if($section==='books'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookModal"><i class="bi bi-plus-lg me-1"></i>Add Book</button><?php endif;?>
      <?php if($section==='tags'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tagModal"><i class="bi bi-plus-lg me-1"></i>Add Tag</button><?php endif;?>
      <?php if($section==='shelves'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#shelfModal"><i class="bi bi-plus-lg me-1"></i>Add Shelf</button><?php endif;?>
      <?php if($section==='lending'):?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#loanModal"><i class="bi bi-plus-lg me-1"></i>Lend Book</button><?php endif;?>
    </div>
  </div>

  <?php if($flash):?>
  <div class="alert alert-<?=h($flash['type']??'info')?> d-flex align-items-center gap-2 mb-4 fade-up" id="flashAlert" role="alert">
    <i class="bi bi-<?=h($flashIcons[$flash['type']??'']??'info-circle-fill')?>"></i>
    <span><?=h($flash['msg'])?></span>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
  <?php endif;?>

<?php if($section==='dashboard'):?>
  <div class="row g-3 mb-4">
    <?php foreach([
      ['Books',count($d['books']),'bi-book','bg-primary-subtle text-primary'],
      ['Tags',count($d['tags']),'bi-bookmark','bg-info-subtle text-info'],
      ['Shelves',count($d['shelves']),'bi-archive','bg-warning-subtle text-warning-emphasis'],
      ['On Loan',$lentCount,'bi-send','bg-danger-subtle text-danger'],
    ] as $i=>$x):?>
    <div class="col-sm-6 col-xl-3 fade-up fade-up-<?=$i+1?>"><div class="card p-4 h-100 shadow-sm">
      <div class="d-flex justify-content-between align-items-start">
        <div><div class="text-secondary small fw-semibold mb-2"><?=$x[0]?></div><div class="display-6 fw-bold"><?=$x[1]?></div></div>
        <div class="<?=$x[3]?> rounded-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;font-size:1.3rem"><i class="bi <?=$x[2]?>"></i></div>
      </div>
    </div></div>
    <?php endforeach;?>
  </div>

  <?php if($topTags):?>
  <div class="row g-3 mb-4">
    <?php foreach($topTags as $i=>$t):?>
    <div class="col-6 col-md-4 col-xl-2 fade-up fade-up-<?=($i%4)+1?>">
      <a class="card p-3 h-100 text-decoration-none tag-stat" href="?section=books&tag=<?=$t['id']?>" title="Show books tagged <?=h($t['name'])?>">
        <div class="d-flex align-items-center justify-content-between">
          <span class="badge bg-primary-subtle text-primary-emphasis"><i class="bi bi-bookmark me-1"></i><?=$t['count']?> book(s)</span>
          <i class="bi bi-box-arrow-up-right text-secondary"></i>
        </div>
        <div class="fw-bold text-truncate mt-2"><?=h($t['name'])?></div>
      </a>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>

  <?php if($overdueLoans):?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-4 fade-up">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span><?=count($overdueLoans)?> loan(s) are past their due date.</span>
    <a href="?section=lending" class="alert-link ms-auto fw-bold">Review →</a>
  </div>
  <?php endif;?>

  <div class="row g-4">
    <div class="col-lg-7 fade-up fade-up-2">
      <div class="card p-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="fw-bold mb-0">Recently Added</h5>
          <a class="link-primary text-decoration-none fw-semibold" href="?section=books">View all <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php $recent=array_slice(array_reverse($d['books']),0,5);?>
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
    <div class="col-lg-5 fade-up fade-up-3">
      <div class="card p-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="fw-bold mb-0">Active Loans</h5>
          <a class="link-primary text-decoration-none fw-semibold" href="?section=lending">Manage <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if(!$activeLoans):?>
          <div class="empty"><i class="bi bi-check2-circle"></i><h6>Nothing on loan</h6><p class="mb-0">All your books are on the shelf.</p></div>
        <?php else:foreach(array_slice($activeLoans,-5) as$l):$b=row_by_id($d['books'],(int)$l['book_id']);$ls=loan_status($l);?>
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
  <div class="card p-3 mb-4 fade-up">
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
        <select class="form-select" name="tag"><option value="">All tags</option><?php foreach($tagsAlpha as$t):$cnt=count(array_filter($d['books'],function($b) use ($t){ return in_array((int)$t['id'],array_map('intval',$b['tag_ids']??[]),true); }));?><option value="<?=$t['id']?>"<?=$tagf===(int)$t['id']?' selected':''?>><?=h($t['name'])?> (<?=$cnt?>)</option><?php endforeach;?></select>
      </div>
      <div class="col-lg-3 col-md-12 d-flex gap-2">
        <button class="btn btn-primary flex-grow-1"><i class="bi bi-search me-1"></i>Search</button>
        <?php if($q!==''||$status!==''||$tagf>0):?><a class="btn btn-outline-secondary" href="?section=books">Reset</a><?php endif;?>
      </div>
    </form>
  </div>

  <div class="card p-3 fade-up fade-up-2">
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
  <div class="card p-3 mb-4 fade-up">
    <form class="row g-2">
      <input type="hidden" name="section" value="tags">
      <div class="col-md-9"><div class="input-icon"><i class="bi bi-search"></i><input class="form-control" name="tag_q" value="<?=h($tagq)?>" placeholder="Search tags..."></div></div>
      <div class="col-md-3"><button class="btn btn-dark w-100">Search</button></div>
    </form>
  </div>
  <div class="card p-3 fade-up fade-up-2">
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Tag</th><th>Books</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach($tags as$t):$use=count(array_filter($d['books'],function($b) use ($t){ return in_array((int)$t['id'],array_map('intval',$b['tag_ids']??[]),true); }));?>
        <tr>
          <td><span class="badge bg-primary-subtle text-primary-emphasis">#<?=$t['id']?> · <?=h($t['name'])?></span></td>
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
  <div class="card p-3 fade-up">
    <div class="table-responsive"><table class="table align-middle">
      <thead><tr><th>Shelf</th><th>Location</th><th>Books</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach($d['shelves']as$s):$use=count(array_filter($d['books'],function($b) use ($s){ return (int)($b['shelf_id']??0)===(int)$s['id']; }));?>
        <tr>
          <td><div class="d-flex align-items-center gap-2"><span class="badge bg-warning-subtle text-warning-emphasis d-flex align-items-center justify-content-center rounded-circle" style="width:38px;height:38px;font-size:1rem"><i class="bi bi-archive"></i></span><span class="fw-semibold"><?=h($s['name'])?></span></div></td>
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
  <div class="card p-3 fade-up">
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
document.addEventListener('DOMContentLoaded',function(){
  <?php if($editBook):?>new bootstrap.Modal(document.getElementById('bookModal')).show();<?php endif;?>
  <?php if($editTag):?>new bootstrap.Modal(document.getElementById('tagModal')).show();<?php endif;?>
  <?php if($editShelf):?>new bootstrap.Modal(document.getElementById('shelfModal')).show();<?php endif;?>
  var flash=document.getElementById('flashAlert');
  if(flash) setTimeout(function(){ bootstrap.Alert.getOrCreateInstance(flash).close(); },4200);
  var links = document.querySelectorAll('.sidebar a');
  for(var i=0;i<links.length;i++){
    links[i].addEventListener('click',function(){
      var off=document.getElementById('sidebar');
      if(window.bootstrap && off.classList.contains('show') && bootstrap.Offcanvas.getInstance(off)) bootstrap.Offcanvas.getInstance(off).close();
    });
  }
});
</script>
</body></html>