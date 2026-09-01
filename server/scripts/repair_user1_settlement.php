<?php
declare(strict_types=1);

// One-off, idempotent repair for the test member user1 (site_users.id=36).
// Run only after taking a database backup:
//   php server/scripts/repair_user1_settlement.php --apply

if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Refusing to change data. Re-run with --apply after backup.\n");
    exit(2);
}

$pdo = new PDO('mysql:host=192.168.2.18;port=3306;dbname=kuaipaisan;charset=utf8mb4', 'root', 'zhangze123..', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->beginTransaction();
try {
    $user = $pdo->query("SELECT id,balance,organization_id,site_id,tenant_id FROM site_users WHERE id=36 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new RuntimeException('user1(id=36) not found');

    // Historical position payouts produced by the pre-fix settlement code.
    $newWins = [479=>4500.00, 480=>4500.00, 481=>4500.00, 506=>9000.00, 518=>9000.00, 524=>4500.00];
    $recordIds = [];
    $winDeltaByDate = [];
    $changes = 0;
    foreach ($newWins as $detailId => $newWin) {
        $q = $pdo->prepare('SELECT id,bet_record_id,issue_no,amount,win_amount,status,placed_at FROM bet_details WHERE id=? AND user_id=36 FOR UPDATE');
        $q->execute([$detailId]); $detail = $q->fetch(PDO::FETCH_ASSOC);
        if (!$detail) throw new RuntimeException("detail #$detailId not found");
        $oldWin = (float)$detail['win_amount'];
        if (abs($oldWin - $newWin) > 0.001) {
            $pdo->prepare('UPDATE bet_details SET win_amount=?, status=?, matched_count=? WHERE id=?')
                ->execute([number_format($newWin,2,'.',''), $newWin>0?'won':'unwon', $newWin>0?1:0, $detailId]);
            $date = substr((string)$detail['placed_at'],0,10);
            $winDeltaByDate[$date] = ($winDeltaByDate[$date] ?? 0.0) + ($newWin - $oldWin);
            $changes++;
        }
        $recordIds[(int)$detail['bet_record_id']] = true;
    }

    // Old batch parser stored 169 stakes as 170 and rounded 33.8 to 34.0.
    $oldAmount = 34.00; $newAmount = 33.80; $oldCount = 170; $newCount = 169;
    $q = $pdo->query("SELECT id,bet_record_id,amount,win_amount,placed_at,number_text FROM bet_details WHERE id=357 AND user_id=36 FOR UPDATE");
    $detail = $q->fetch(PDO::FETCH_ASSOC);
    if (!$detail) throw new RuntimeException('detail #357 not found');
    if (abs((float)$detail['amount']-$oldAmount)<0.001) {
        $numberText = preg_replace('/\s+169直$/u','',(string)$detail['number_text']) ?? (string)$detail['number_text'];
        $pdo->prepare('UPDATE bet_details SET amount=?,number_text=? WHERE id=357')->execute([number_format($newAmount,2,'.',''),$numberText]);
        $date=substr((string)$detail['placed_at'],0,10);
        $winDeltaByDate[$date]=$winDeltaByDate[$date]??0.0;
        $changes++;
    }
    $recordIds[(int)$detail['bet_record_id']] = true;

    $amountDeltaByDate=['2026-08-31'=>0.0,'2026-09-01'=>0.0];
    $balanceDelta = 0.0;
    foreach (array_keys($recordIds) as $recordId) {
        $r=$pdo->prepare('SELECT id,amount,win_amount,bet_count,status,placed_at,submission_id FROM bet_records WHERE id=? AND user_id=36 FOR UPDATE');
        $r->execute([$recordId]);$record=$r->fetch(PDO::FETCH_ASSOC);if(!$record)throw new RuntimeException("record #$recordId not found");
        $sum=(float)$pdo->query('SELECT COALESCE(SUM(win_amount),0) FROM bet_details WHERE bet_record_id='.(int)$recordId)->fetchColumn();
        $status=$sum>0?'won':'unwon';
        if ($recordId===276 && abs((float)$record['amount']-$oldAmount)<0.001) {
            $pdo->prepare('UPDATE bet_records SET amount=?,bet_count=? WHERE id=?')->execute([number_format($newAmount,2,'.',''),$newCount,$recordId]);
            $amountDeltaByDate['2026-08-31'] += $newAmount-$oldAmount;
            $balanceDelta += $newAmount-$oldAmount;
        }
        if (abs((float)$record['win_amount']-$sum)>0.001 || (string)$record['status']!==$status)
            $pdo->prepare('UPDATE bet_records SET win_amount=?,status=? WHERE id=?')->execute([number_format($sum,2,'.',''),$status,$recordId]);
    }

    if ($changes === 0) {
        $pdo->rollBack();
        echo "already repaired; no changes made\n";
        exit(0);
    }

    foreach ([133,183,198,202,205] as $submissionId) {
        $q=$pdo->prepare('SELECT COALESCE(SUM(bet_count),0),COALESCE(SUM(amount),0),COALESCE(SUM(win_amount),0),MAX(status),MAX(sealed) FROM bet_records WHERE submission_id=?');
        $q->execute([$submissionId]);$s=$q->fetch(PDO::FETCH_NUM);if(!$s||$s[0]===null)continue;
        $status=(float)$s[2]>0?'won':((string)$s[3]==='pending'?'pending':'unwon');
        $pdo->prepare('UPDATE bet_submissions SET bet_count=?,amount=?,win_amount=?,status=?,sealed=? WHERE id=?')->execute([(int)$s[0],number_format((float)$s[1],2,'.',''),number_format((float)$s[2],2,'.',''),$status,(int)$s[4],$submissionId]);
    }

    foreach ($amountDeltaByDate as $date=>$delta) {
        $winDelta=$winDeltaByDate[$date]??0.0;
        if (abs($delta)<0.0001 && abs($winDelta)<0.0001) continue;
        $pdo->prepare('UPDATE bills SET amount=amount+?,win_amount=win_amount+?,profit=profit+?-? WHERE user_id=36 AND bill_date=?')
            ->execute([$delta,$winDelta,$winDelta,$delta,$date]);
    }

    $totalWinDelta=array_sum($winDeltaByDate);
    $totalBalanceDelta=$totalWinDelta+$balanceDelta;
    $before=(float)$user['balance'];$after=$before+$totalBalanceDelta;
    $pdo->prepare('UPDATE site_users SET balance=?,updated_at=NOW() WHERE id=36')->execute([number_format($after,2,'.','')]);
    $pdo->prepare("INSERT INTO organization_credit_ledger (transaction_no,tenant_id,site_id,organization_id,account_type,account_id,related_user_id,issue_no,direction,amount,balance_before,balance_after,reason,source_type,category,metadata,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute(['REPAIR-U36-'.date('YmdHis'),$user['tenant_id'],$user['site_id'],null,'user',36,36,null,$totalBalanceDelta>=0?'in':'out',number_format(abs($totalBalanceDelta),2,'.',''),number_format($before,2,'.',''),number_format($after,2,'.',''),'纠正历史定位结算','manual_adjustment','adjustment',json_encode(['win_delta'=>$totalWinDelta,'amount_delta'=>$balanceDelta,'details'=>array_keys($newWins)],JSON_UNESCAPED_UNICODE)]);

    // Restore each organization balance by the change in the sequential
    // profit-share allocation caused by the corrected member payouts.
    $organizationDelta = [42=>1403999.96, 41=>4492799.87, 40=>898559.98, 39=>179711.99, 38=>44928.00];
    foreach ($organizationDelta as $organizationId=>$delta) {
        if (abs($delta)<0.005) continue;
        $q=$pdo->prepare('SELECT balance FROM organization_nodes WHERE id=? FOR UPDATE');$q->execute([$organizationId]);$orgBefore=(float)$q->fetchColumn();$orgAfter=$orgBefore+$delta;
        $pdo->prepare('UPDATE organization_nodes SET balance=?,updated_at=NOW() WHERE id=?')->execute([number_format($orgAfter,2,'.',''),$organizationId]);
        $pdo->prepare("INSERT INTO organization_credit_ledger (transaction_no,tenant_id,site_id,organization_id,account_type,account_id,related_user_id,direction,amount,balance_before,balance_after,reason,source_type,category,metadata,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute(['REPAIR-U36-ORG-'.$organizationId.'-'.date('YmdHis'),$user['tenant_id'],$user['site_id'],$organizationId,'organization',$organizationId,36,$delta>=0?'in':'out',number_format(abs($delta),2,'.',''),number_format($orgBefore,2,'.',''),number_format($orgAfter,2,'.',''),'纠正历史定位结算','manual_adjustment','adjustment',json_encode(['user_id'=>36],JSON_UNESCAPED_UNICODE)]);
    }

    $pdo->commit();
    echo "repair applied: win_delta=".number_format($totalWinDelta,2,'.','')." balance_delta=".number_format($totalBalanceDelta,2,'.','')." new_balance=".number_format($after,2,'.','')."\n";
} catch (Throwable $e) {
    $pdo->rollBack(); fwrite(STDERR,'repair rolled back: '.$e->getMessage()."\n"); exit(1);
}
