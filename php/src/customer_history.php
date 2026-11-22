<?php
declare(strict_types=1);
function ensure_customer_history(): void
{
    static $done=false; if($done)return;
    run('CREATE TABLE IF NOT EXISTS customer_order_history (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, action VARCHAR(100) NOT NULL, before_state MEDIUMTEXT NOT NULL, after_state MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, undone_at DATETIME NULL, KEY coi_history_order(order_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done=true;
}
function customer_state(int $id): array
{
    $o=row('SELECT ready_at,delivered_at FROM customer_orders WHERE id=?',[$id]);
    $items=rows('SELECT id,qty,ordered,ready_qty FROM customer_order_items WHERE order_id=? ORDER BY id',[$id]);
    foreach($items as &$i) foreach(['id','qty','ordered','ready_qty'] as $k) $i[$k]=(int)$i[$k]; unset($i);
    return ['order'=>$o,'items'=>$items];
}
function customer_state_key(array $state): string {return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));}
function customer_history_record(int $id,string $action,array $before): void
{
    $after=customer_state($id); if($before===$after)return;
    run('INSERT INTO customer_order_history(order_id,action,before_state,after_state,created_at) VALUES(?,?,?,?,?)',[$id,mb_substr($action,0,100),json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),now_jst()]);
}
function customer_lock_state(int $id): array
{
    if(!val('SELECT id FROM customer_orders WHERE id=? FOR UPDATE',[$id]))throw new HttpError(404);
    $state=customer_state($id); $d=req_json()??[];
    if(isset($d['state_key']) && !hash_equals(customer_state_key($state),(string)$d['state_key'])) throw new HttpError(409);
    return $state;
}
function customer_history_list(int $id): array
{
    $list=rows('SELECT id,action,created_at,undone_at,after_state FROM customer_order_history WHERE order_id=? ORDER BY id DESC LIMIT 20',[$id]);
    $current=customer_state_key(customer_state($id));
    foreach($list as $n=>&$h){$h['can_undo']=$n===0 && !in_array($h['action'],['操作を元に戻す','注文内容を編集'],true) && $h['undone_at']===null && hash_equals($current,customer_state_key(json_decode($h['after_state'],true,512,JSON_THROW_ON_ERROR))); unset($h['after_state']); $h['stamp']=jst_fmt($h['created_at']);}unset($h);
    return $list;
}
function customer_status_response(int $id): array
{
    $o=row('SELECT ready_at,delivered_at FROM customer_orders WHERE id=?',[$id]);
    $items=rows('SELECT id,qty,ready_qty,ordered FROM customer_order_items WHERE order_id=? ORDER BY id',[$id]);
    $ready=array_values(array_map(static fn($i)=>(int)$i['id'],array_filter($items,static fn($i)=>(int)$i['ready_qty']>=(int)$i['qty'])));
    return ['ok'=>true,'ready'=>count($items)>0 && count($ready)===count($items),'delivered'=>$o['delivered_at']!==null,'ready_at'=>status_stamp($o['ready_at']),'delivered_at'=>status_stamp($o['delivered_at']),'ready_items'=>$ready,'ready_quantities'=>(object)array_column($items,'ready_qty','id'),'ordered_items'=>array_values(array_map(static fn($i)=>(int)$i['id'],array_filter($items,static fn($i)=>(bool)$i['ordered']))),'ready_units'=>array_sum(array_column($items,'ready_qty')),'total_units'=>array_sum(array_column($items,'qty')),'ready_count'=>count($ready),'item_count'=>count($items),'state_key'=>customer_state_key(customer_state($id)),'history'=>customer_history_list($id)];
}
function customer_history_form_key(): string {return hash_hmac('sha256','customer-history|'.(string)($_COOKIE['zlogin']??''),admin_pass());}
function h_customer_order_undo(int $id,int $hid): void
{
    ensure_customer_status_columns(); ensure_customer_history();
    if(!hash_equals(customer_history_form_key(),(string)((req_json()??[])['form_key']??'')))throw new HttpError(403);
    $result=transaction(function()use($id,$hid){
        $current=customer_lock_state($id);
        $h=row('SELECT * FROM customer_order_history WHERE order_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$id]);
        if(!$h || (int)$h['id']!==$hid || in_array($h['action'],['操作を元に戻す','注文内容を編集'],true) || $h['undone_at']!==null)throw new HttpError(409);
        $after=json_decode($h['after_state'],true,512,JSON_THROW_ON_ERROR);
        if($after!==$current)throw new HttpError(409);
        $before=json_decode($h['before_state'],true,512,JSON_THROW_ON_ERROR);
        foreach($before['items'] as $i) run('UPDATE customer_order_items SET ordered=?,ready_qty=?,ready=? WHERE id=? AND order_id=?',[$i['ordered'],$i['ready_qty'],$i['ready_qty']>=$i['qty'],$i['id'],$id]);
        run('UPDATE customer_orders SET ready_at=?,delivered_at=? WHERE id=?',[$before['order']['ready_at'],$before['order']['delivered_at'],$id]);
        run('UPDATE customer_order_history SET undone_at=? WHERE id=?',[now_jst(),$hid]);
        customer_history_record($id,'操作を元に戻す',$current);
        return customer_status_response($id);
    }); respond_json($result);
}
